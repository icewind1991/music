<?php declare(strict_types=1);

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2019 - 2021
 */

namespace OCA\Music\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\DataDisplayResponse;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\AppFramework\Http\RedirectResponse;
use \OCP\Files\File;
use \OCP\Files\Folder;
use \OCP\IRequest;
use \OCP\IUserManager;
use \OCP\IURLGenerator;

use \OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use \OCA\Music\AppFramework\Core\Logger;
use \OCA\Music\AppFramework\Utility\MethodAnnotationReader;
use \OCA\Music\AppFramework\Utility\RequestParameterExtractor;
use \OCA\Music\AppFramework\Utility\RequestParameterExtractorException;

use \OCA\Music\BusinessLayer\AlbumBusinessLayer;
use \OCA\Music\BusinessLayer\ArtistBusinessLayer;
use \OCA\Music\BusinessLayer\BookmarkBusinessLayer;
use \OCA\Music\BusinessLayer\GenreBusinessLayer;
use \OCA\Music\BusinessLayer\Library;
use \OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use \OCA\Music\BusinessLayer\PodcastChannelBusinessLayer;
use \OCA\Music\BusinessLayer\PodcastEpisodeBusinessLayer;
use \OCA\Music\BusinessLayer\RadioStationBusinessLayer;
use \OCA\Music\BusinessLayer\TrackBusinessLayer;

use \OCA\Music\Db\Album;
use \OCA\Music\Db\Artist;
use \OCA\Music\Db\Bookmark;
use \OCA\Music\Db\Genre;
use \OCA\Music\Db\Playlist;
use \OCA\Music\Db\PodcastEpisode;
use \OCA\Music\Db\SortBy;
use \OCA\Music\Db\Track;

use \OCA\Music\Http\FileResponse;
use \OCA\Music\Http\FileStreamResponse;
use \OCA\Music\Http\XmlResponse;

use \OCA\Music\Middleware\SubsonicException;

use \OCA\Music\Utility\CoverHelper;
use \OCA\Music\Utility\DetailsHelper;
use \OCA\Music\Utility\LastfmService;
use \OCA\Music\Utility\PodcastService;
use \OCA\Music\Utility\Random;
use \OCA\Music\Utility\UserMusicFolder;
use \OCA\Music\Utility\Util;

class SubsonicController extends Controller {
	const API_VERSION = '1.13.0';

	private $albumBusinessLayer;
	private $artistBusinessLayer;
	private $bookmarkBusinessLayer;
	private $genreBusinessLayer;
	private $playlistBusinessLayer;
	private $podcastChannelBusinessLayer;
	private $podcastEpisodeBusinessLayer;
	private $radioStationBusinessLayer;
	private $trackBusinessLayer;
	private $library;
	private $urlGenerator;
	private $userManager;
	private $userMusicFolder;
	private $l10n;
	private $coverHelper;
	private $detailsHelper;
	private $lastfmService;
	private $podcastService;
	private $random;
	private $logger;
	private $userId;
	private $format;
	private $callback;

	public function __construct($appname,
								IRequest $request,
								$l10n,
								IURLGenerator $urlGenerator,
								IUserManager $userManager,
								AlbumBusinessLayer $albumBusinessLayer,
								ArtistBusinessLayer $artistBusinessLayer,
								BookmarkBusinessLayer $bookmarkBusinessLayer,
								GenreBusinessLayer $genreBusinessLayer,
								PlaylistBusinessLayer $playlistBusinessLayer,
								PodcastChannelBusinessLayer $podcastChannelBusinessLayer,
								PodcastEpisodeBusinessLayer $podcastEpisodeBusinessLayer,
								RadioStationBusinessLayer $radioStationBusinessLayer,
								TrackBusinessLayer $trackBusinessLayer,
								Library $library,
								UserMusicFolder $userMusicFolder,
								CoverHelper $coverHelper,
								DetailsHelper $detailsHelper,
								LastfmService $lastfmService,
								PodcastService $podcastService,
								Random $random,
								Logger $logger) {
		parent::__construct($appname, $request);

		$this->albumBusinessLayer = $albumBusinessLayer;
		$this->artistBusinessLayer = $artistBusinessLayer;
		$this->bookmarkBusinessLayer = $bookmarkBusinessLayer;
		$this->genreBusinessLayer = $genreBusinessLayer;
		$this->playlistBusinessLayer = $playlistBusinessLayer;
		$this->podcastChannelBusinessLayer = $podcastChannelBusinessLayer;
		$this->podcastEpisodeBusinessLayer = $podcastEpisodeBusinessLayer;
		$this->radioStationBusinessLayer = $radioStationBusinessLayer;
		$this->trackBusinessLayer = $trackBusinessLayer;
		$this->library = $library;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->userMusicFolder = $userMusicFolder;
		$this->coverHelper = $coverHelper;
		$this->detailsHelper = $detailsHelper;
		$this->lastfmService = $lastfmService;
		$this->podcastService = $podcastService;
		$this->random = $random;
		$this->logger = $logger;
	}

	/**
	 * Called by the middleware to set the reponse format to be used
	 * @param string $format Response format: xml/json/jsonp
	 * @param string|null $callback Function name to use if the @a $format is 'jsonp'
	 */
	public function setResponseFormat(string $format, string $callback = null) {
		$this->format = $format;
		$this->callback = $callback;
	}

	/**
	 * Called by the middleware once the user credentials have been checked
	 * @param string $userId
	 */
	public function setAuthenticatedUser(string $userId) {
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function handleRequest($method) {
		$this->logger->log("Subsonic request $method", 'debug');

		// Allow calling all methods with or without the postfix ".view"
		if (Util::endsWith($method, ".view")) {
			$method = \substr($method, 0, -\strlen(".view"));
		}

		// Allow calling any functions annotated to be part of the API
		if (\method_exists($this, $method)) {
			$annotationReader = new MethodAnnotationReader($this, $method);
			if ($annotationReader->hasAnnotation('SubsonicAPI')) {
				$parameterExtractor = new RequestParameterExtractor($this->request);
				try {
					$parameterValues = $parameterExtractor->getParametersForMethod($this, $method);
				} catch (RequestParameterExtractorException $ex) {
					return $this->subsonicErrorResponse(10, $ex->getMessage());
				}
				return \call_user_func_array([$this, $method], $parameterValues);
			}
		}

		$this->logger->log("Request $method not supported", 'warn');
		return $this->subsonicErrorResponse(70, "Requested action $method is not supported");
	}

	/* -------------------------------------------------------------------------
	 * REST API methods
	 *------------------------------------------------------------------------*/

	/**
	 * @SubsonicAPI
	 */
	private function ping() {
		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getLicense() {
		return $this->subsonicResponse([
			'license' => [
				'valid' => 'true'
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getMusicFolders() {
		// Only single root folder is supported
		return $this->subsonicResponse([
			'musicFolders' => ['musicFolder' => [
				['id' => 'artists', 'name' => $this->l10n->t('Artists')],
				['id' => 'folders', 'name' => $this->l10n->t('Folders')]
			]]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getIndexes(string $musicFolderId) {
		if ($musicFolderId === 'folders') {
			return $this->getIndexesForFolders();
		} else {
			return $this->getIndexesForArtists();
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function getMusicDirectory(string $id) {
		if (Util::startsWith($id, 'folder-')) {
			return $this->getMusicDirectoryForFolder($id);
		} elseif (Util::startsWith($id, 'artist-')) {
			return $this->getMusicDirectoryForArtist($id);
		} elseif (Util::startsWith($id, 'album-')) {
			return $this->getMusicDirectoryForAlbum($id);
		} elseif (Util::startsWith($id, 'podcast_channel-')) {
			return $this->getMusicDirectoryForPodcastChannel($id);
		} else {
			throw new SubsonicException("Unsupported id format $id");
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function getAlbumList(
			string $type, ?string $genre, ?int $fromYear, ?int $toYear, int $size=10, int $offset=0) {
		$albums = $this->albumsForGetAlbumList($type, $genre, $fromYear, $toYear, $size, $offset);
		return $this->subsonicResponse(['albumList' =>
				['album' => \array_map([$this, 'albumToOldApi'], $albums)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getAlbumList2(
			string $type, ?string $genre, ?int $fromYear, ?int $toYear, int $size=10, int $offset=0) {
		/*
		 * According to the API specification, the difference between this and getAlbumList
		 * should be that this function would organize albums according the metadata while
		 * getAlbumList would organize them by folders. However, we organize by metadata
		 * also in getAlbumList, because that's more natural for the Music app and many/most
		 * clients do not support getAlbumList2.
		 */
		$albums = $this->albumsForGetAlbumList($type, $genre, $fromYear, $toYear, $size, $offset);
		return $this->subsonicResponse(['albumList2' =>
				['album' => \array_map([$this, 'albumToNewApi'], $albums)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getArtists() {
		return $this->getIndexesForArtists('artists');
	}

	/**
	 * @SubsonicAPI
	 */
	private function getArtist(string $id) {
		$artistId = self::ripIdPrefix($id); // get rid of 'artist-' prefix

		$artist = $this->artistBusinessLayer->find($artistId, $this->userId);
		$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $this->userId);

		$artistNode = $this->artistToApi($artist);
		$artistNode['album'] = \array_map([$this, 'albumToNewApi'], $albums);

		return $this->subsonicResponse(['artist' => $artistNode]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getArtistInfo(string $id, bool $includeNotPresent=false) {
		return $this->doGetArtistInfo('artistInfo', $id, $includeNotPresent);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getArtistInfo2(string $id, bool $includeNotPresent=false) {
		return $this->doGetArtistInfo('artistInfo2', $id, $includeNotPresent);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getSimilarSongs(string $id, int $count=50) {
		return $this->doGetSimilarSongs('similarSongs', $id, $count);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getSimilarSongs2(string $id, int $count=50) {
		return $this->doGetSimilarSongs('similarSongs2', $id, $count);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getTopSongs(string $artist, int $count=50) {
		$tracks = $this->lastfmService->getTopTracks($artist, $this->userId, $count);
		return $this->subsonicResponse(['topSongs' =>
			['song' => \array_map([$this, 'trackToApi'], $tracks)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getAlbum(string $id) {
		$albumId = self::ripIdPrefix($id); // get rid of 'album-' prefix

		$album = $this->albumBusinessLayer->find($albumId, $this->userId);
		$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId);

		$albumNode = $this->albumToNewApi($album);
		$albumNode['song'] = \array_map(function ($track) use ($album) {
			$track->setAlbum($album);
			return $this->trackToApi($track);
		}, $tracks);
		return $this->subsonicResponse(['album' => $albumNode]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getSong(string $id) {
		$trackId = self::ripIdPrefix($id); // get rid of 'track-' prefix
		$track = $this->trackBusinessLayer->find($trackId, $this->userId);

		return $this->subsonicResponse(['song' => $this->trackToApi($track)]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getRandomSongs(?string $genre, ?string $fromYear, ?string $toYear, int $size=10) {
		$size = \min($size, 500); // the API spec limits the maximum amount to 500

		if ($genre !== null) {
			$trackPool = $this->findTracksByGenre($genre);
		} else {
			$trackPool = $this->trackBusinessLayer->findAll($this->userId);
		}

		if ($fromYear !== null) {
			$trackPool = \array_filter($trackPool, function ($track) use ($fromYear) {
				return ($track->getYear() !== null && $track->getYear() >= $fromYear);
			});
		}

		if ($toYear !== null) {
			$trackPool = \array_filter($trackPool, function ($track) use ($toYear) {
				return ($track->getYear() !== null && $track->getYear() <= $toYear);
			});
		}

		$tracks = Random::pickItems($trackPool, $size);

		return $this->subsonicResponse(['randomSongs' =>
				['song' => \array_map([$this, 'trackToApi'], $tracks)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getCoverArt(string $id, ?int $size) {
		$idParts = \explode('-', $id);
		$type = $idParts[0];
		$entityId = (int)($idParts[1]);

		if ($type == 'album') {
			$entity = $this->albumBusinessLayer->find($entityId, $this->userId);
		} elseif ($type == 'artist') {
			$entity = $this->artistBusinessLayer->find($entityId, $this->userId);
		} elseif ($type == 'podcast_channel') {
			$entity = $this->podcastService->getChannel($entityId, $this->userId, /*$includeEpisodes=*/ false);
		}

		if (!empty($entity)) {
			$rootFolder = $this->userMusicFolder->getFolder($this->userId);
			$coverData = $this->coverHelper->getCover($entity, $this->userId, $rootFolder, $size);

			if ($coverData !== null) {
				return new FileResponse($coverData);
			}
		}

		return $this->subsonicErrorResponse(70, "entity $id has no cover");
	}

	/**
	 * @SubsonicAPI
	 */
	private function getLyrics(?string $artist, ?string $title) {
		$matches = $this->trackBusinessLayer->findAllByNameAndArtistName($title, $artist, $this->userId);
		$matchingCount = \count($matches);

		if ($matchingCount === 0) {
			$this->logger->log("No matching track for title '$title' and artist '$artist'", 'debug');
			return $this->subsonicResponse(['lyrics' => new \stdClass]);
		} else {
			if ($matchingCount > 1) {
				$this->logger->log("Found $matchingCount tracks matching title ".
								"'$title' and artist '$artist'; using the first", 'debug');
			}
			$track = $matches[0];

			$artistObj = $this->artistBusinessLayer->find($track->getArtistId(), $this->userId);
			$rootFolder = $this->userMusicFolder->getFolder($this->userId);
			$lyrics = $this->detailsHelper->getLyrics($track->getFileId(), $rootFolder);

			return $this->subsonicResponse(['lyrics' => [
					'artist' => $artistObj->getNameString($this->l10n),
					'title' => $track->getTitle(),
					'value' => $lyrics
			]]);
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function stream(string $id) {
		// We don't support transcaoding, so 'stream' and 'download' act identically
		return $this->download($id);
	}

	/**
	 * @SubsonicAPI
	 */
	private function download(string $id) {
		$idParts = \explode('-', $id);
		$type = $idParts[0];
		$entityId = (int)($idParts[1]);

		if ($type === 'track') {
			$track = $this->trackBusinessLayer->find($entityId, $this->userId);
			$file = $this->getFilesystemNode($track->getFileId());

			if ($file instanceof File) {
				return new FileStreamResponse($file);
			} else {
				return $this->subsonicErrorResponse(70, 'file not found');
			}
		} elseif ($type === 'podcast_episode') {
			$episode = $this->podcastService->getEpisode($entityId, $this->userId);
			if ($episode instanceof PodcastEpisode) {
				return new RedirectResponse($episode->getStreamUrl());
			} else {
				return $this->subsonicErrorResponse(70, 'episode not found');
			}
		} else {
			return $this->subsonicErrorResponse(0, "id of type $type not supported");
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function search2(string $query, int $artistCount=20, int $artistOffset=0,
			int $albumCount=20, int $albumOffset=0, int $songCount=20, int $songOffset=0) {
		$results = $this->doSearch($query, $artistCount, $artistOffset, $albumCount, $albumOffset, $songCount, $songOffset);
		return $this->searchResponse('searchResult2', $results, /*$useNewApi=*/false);
	}

	/**
	 * @SubsonicAPI
	 */
	private function search3(string $query, int $artistCount=20, int $artistOffset=0,
			int $albumCount=20, int $albumOffset=0, int $songCount=20, int $songOffset=0) {
		$results = $this->doSearch($query, $artistCount, $artistOffset, $albumCount, $albumOffset, $songCount, $songOffset);
		return $this->searchResponse('searchResult3', $results, /*$useNewApi=*/true);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getGenres() {
		$genres = $this->genreBusinessLayer->findAll($this->userId, SortBy::Name);

		return $this->subsonicResponse(['genres' =>
			[
				'genre' => \array_map(function ($genre) {
					return [
						'songCount' => $genre->getTrackCount(),
						'albumCount' => $genre->getAlbumCount(),
						'value' => $genre->getNameString($this->l10n)
					];
				},
				$genres)
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getSongsByGenre(string $genre, int $count=10, int $offset=0) {
		$tracks = $this->findTracksByGenre($genre, $count, $offset);

		return $this->subsonicResponse(['songsByGenre' =>
			['song' => \array_map([$this, 'trackToApi'], $tracks)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getPlaylists() {
		$playlists = $this->playlistBusinessLayer->findAll($this->userId);

		return $this->subsonicResponse(['playlists' =>
			['playlist' => \array_map([$this, 'playlistToApi'], $playlists)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getPlaylist(int $id) {
		$playlist = $this->playlistBusinessLayer->find($id, $this->userId);
		$tracks = $this->playlistBusinessLayer->getPlaylistTracks($id, $this->userId);

		$playlistNode = $this->playlistToApi($playlist);
		$playlistNode['entry'] = \array_map([$this, 'trackToApi'], $tracks);

		return $this->subsonicResponse(['playlist' => $playlistNode]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function createPlaylist(?string $name, ?string $playlistId, array $songId) {
		$songIds = \array_map('self::ripIdPrefix', $songId);

		// If playlist ID has been passed, then this method actually updates an existing list instead of creating a new one.
		// The updating can't be used to rename the list, even if both ID and name are given (this is how the real Subsonic works, too).
		if (!empty($playlistId)) {
			$playlist = $this->playlistBusinessLayer->find((int)$playlistId, $this->userId);
		} elseif (!empty($name)) {
			$playlist = $this->playlistBusinessLayer->create($name, $this->userId);
		} else {
			throw new SubsonicException('Playlist ID or name must be specified.', 10);
		}

		$playlist->setTrackIdsFromArray($songIds);
		$this->playlistBusinessLayer->update($playlist);

		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function updatePlaylist(int $playlistId, ?string $name, ?string $comment, array $songIdToAdd, array $songIndexToRemove) {
		$songIdsToAdd = \array_map('self::ripIdPrefix', $songIdToAdd);
		$songIndicesToRemove = \array_map('intval', $songIndexToRemove);

		if (!empty($name)) {
			$this->playlistBusinessLayer->rename($name, $playlistId, $this->userId);
		}

		if ($comment!== null) {
			$this->playlistBusinessLayer->setComment($comment, $playlistId, $this->userId);
		}

		if (!empty($songIndicesToRemove)) {
			$this->playlistBusinessLayer->removeTracks($songIndicesToRemove, $playlistId, $this->userId);
		}

		if (!empty($songIdsToAdd)) {
			$this->playlistBusinessLayer->addTracks($songIdsToAdd, $playlistId, $this->userId);
		}

		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function deletePlaylist(int $id) {
		$this->playlistBusinessLayer->delete($id, $this->userId);
		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getInternetRadioStations() {
		$stations = $this->radioStationBusinessLayer->findAll($this->userId);

		return $this->subsonicResponse(['internetRadioStations' =>
				['internetRadioStation' => \array_map(function($station) {
					return [
						'id' => $station->getId(),
						'name' => $station->getName(),
						'streamUrl' => $station->getStreamUrl()
					];
				}, $stations)]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getUser(string $username) {
		if ($username != $this->userId) {
			throw new SubsonicException("{$this->userId} is not authorized to get details for other users.", 50);
		}

		return $this->subsonicResponse([
			'user' => [
				'username' => $username,
				'email' => '',
				'scrobblingEnabled' => false,
				'adminRole' => false,
				'settingsRole' => false,
				'downloadRole' => true,
				'uploadRole' => false,
				'playlistRole' => true,
				'coverArtRole' => false,
				'commentRole' => false,
				'podcastRole' => true,
				'streamRole' => true,
				'jukeboxRole' => false,
				'shareRole' => false,
				'videoConversionRole' => false,
				'folder' => ['artists', 'folders'],
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getUsers() {
		throw new SubsonicException("{$this->userId} is not authorized to get details for other users.", 50);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getAvatar(string $username) {
		if ($username != $this->userId) {
			throw new SubsonicException("{$this->userId} is not authorized to get avatar for other users.", 50);
		}

		$image = $this->userManager->get($username)->getAvatarImage(150);

		if ($image !== null) {
			return new FileResponse(['content' => $image->data(), 'mimetype' => $image->mimeType()]);
		} else {
			return $this->subsonicErrorResponse(70, 'user has no avatar');
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function star(array $id, array $albumId, array $artistId) {
		$targetIds = self::parseStarringParameters($id, $albumId, $artistId);

		$this->trackBusinessLayer->setStarred($targetIds['tracks'], $this->userId);
		$this->albumBusinessLayer->setStarred($targetIds['albums'], $this->userId);
		$this->artistBusinessLayer->setStarred($targetIds['artists'], $this->userId);
		$this->podcastChannelBusinessLayer->setStarred($targetIds['podcast_channels'], $this->userId);
		$this->podcastEpisodeBusinessLayer->setStarred($targetIds['podcast_episodes'], $this->userId);

		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function unstar(array $id, array $albumId, array $artistId) {
		$targetIds = self::parseStarringParameters($id, $albumId, $artistId);

		$this->trackBusinessLayer->unsetStarred($targetIds['tracks'], $this->userId);
		$this->albumBusinessLayer->unsetStarred($targetIds['albums'], $this->userId);
		$this->artistBusinessLayer->unsetStarred($targetIds['artists'], $this->userId);
		$this->podcastChannelBusinessLayer->unsetStarred($targetIds['podcast_channels'], $this->userId);
		$this->podcastEpisodeBusinessLayer->unsetStarred($targetIds['podcast_episodes'], $this->userId);

		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getStarred() {
		$starred = $this->doGetStarred();
		return $this->searchResponse('starred', $starred, /*$useNewApi=*/false);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getStarred2() {
		$starred = $this->doGetStarred();
		return $this->searchResponse('starred2', $starred, /*$useNewApi=*/true);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getVideos() {
		// Feature not supported, return an empty list
		return $this->subsonicResponse([
			'videos' => [
				'video' => []
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getPodcasts(?string $id, bool $includeEpisodes = true) {
		if ($id !== null) {
			$id = self::ripIdPrefix($id);
			$channel = $this->podcastService->getChannel($id, $this->userId, $includeEpisodes);
			if ($channel === null) {
				throw new SubsonicException('Requested channel not found', 70);
			}
			$channels = [$channel];
		} else {
			$channels = $this->podcastService->getAllChannels($this->userId, $includeEpisodes);
		}

		return $this->subsonicResponse([
			'podcasts' => [
				'channel' => Util::arrayMapMethod($channels, 'toSubsonicApi')
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getNewestPodcasts(int $count=20) {
		$episodes = $this->podcastService->getLatestEpisodes($this->userId, $count);

		return $this->subsonicResponse([
			'newestPodcasts' => [
				'episode' => Util::arrayMapMethod($episodes, 'toSubsonicApi')
			]
		]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function refreshPodcasts() {
		$this->podcastService->updateAllChannels($this->userId);
		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function createPodcastChannel(string $url) {
		$result = $this->podcastService->subscribe($url, $this->userId);

		switch ($result['status']) {
			case PodcastService::STATUS_OK:
				return $this->subsonicResponse([]);
			case PodcastService::STATUS_INVALID_URL:
				throw new SubsonicException("Invalid URL $url", 0);
			case PodcastService::STATUS_INVALID_RSS:
				throw new SubsonicException("The document at URL $url is not a valid podcast RSS feed", 0);
			case PodcastService::STATUS_ALREADY_EXISTS:
				throw new SubsonicException('User already has this podcast channel subscribed', 0);
			default:
				throw new SubsonicException("Unexpected status code {$result['status']}", 0);
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function deletePodcastChannel(string $id) {
		$id = self::ripIdPrefix($id);
		$status = $this->podcastService->unsubscribe($id, $this->userId);

		switch ($status) {
			case PodcastService::STATUS_OK:
				return $this->subsonicResponse([]);
			case PodcastService::STATUS_NOT_FOUND:
				throw new SubsonicException('Channel to be deleted not found', 70);
			default:
				throw new SubsonicException("Unexpected status code $status", 0);
		}
	}

	/**
	 * @SubsonicAPI
	 */
	private function getBookmarks() {
		$bookmarkNodes = [];
		$bookmarks = $this->bookmarkBusinessLayer->findAll($this->userId);

		foreach ($bookmarks as $bookmark) {
			$node = $bookmark->toSubsonicApi();
			$entryId = $bookmark->getEntryId();
			$type = $bookmark->getType();

			try {
				if ($type === Bookmark::TYPE_TRACK) {
					$node['entry'] = $this->trackToApi($this->trackBusinessLayer->find($entryId, $this->userId));
				} elseif ($type === Bookmark::TYPE_PODCAST_EPISODE) {
					$node['entry'] = $this->podcastEpisodeBusinessLayer->find($entryId, $this->userId)->toSubsonicApi();
				} else {
					$this->logger->log("Bookmark {$bookmark->getId()} had unexpected entry type $type", 'warn');
				}
				$bookmarkNodes[] = $node;
			} catch (BusinessLayerException $e) {
				$this->logger->log("Bookmarked entry with type $type and id $entryId not found", 'warn');
			}
		}

		return $this->subsonicResponse(['bookmarks' => ['bookmark' => $bookmarkNodes]]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function createBookmark(string $id, int $position, ?string $comment) {
		list($type, $entityId) = self::parseBookamrkIdParam($id);
		$this->bookmarkBusinessLayer->addOrUpdate($this->userId, $type, $entityId, $position, $comment);
		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function deleteBookmark(string $id) {
		list($type, $entityId) = self::parseBookamrkIdParam($id);

		$bookmark = $this->bookmarkBusinessLayer->findByEntry($type, $entityId, $this->userId);
		$this->bookmarkBusinessLayer->delete($bookmark->getId(), $this->userId);

		return $this->subsonicResponse([]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function getPlayQueue() {
		// TODO: not supported yet
		return $this->subsonicResponse(['playQueue' => []]);
	}

	/**
	 * @SubsonicAPI
	 */
	private function savePlayQueue() {
		// TODO: not supported yet
		return $this->subsonicResponse([]);
	}

	/* -------------------------------------------------------------------------
	 * Helper methods
	 *------------------------------------------------------------------------*/

	private static function ensureParamHasValue(string $paramName, /*mixed*/ $paramValue) {
		if ($paramValue === null || $paramValue === '') {
			throw new SubsonicException("Required parameter '$paramName' missing", 10);
		}
	}

	private static function parseBookamrkIdParam(string $id) : array {
		list($typeName, $entityId) = \explode('-', $id);

		if ($typeName === 'track') {
			$type = Bookmark::TYPE_TRACK;
		} elseif ($typeName === 'podcast_episode') {
			$type = Bookmark::TYPE_PODCAST_EPISODE;
		} else {
			throw new SubsonicException("Unsupported ID format $id", 0);
		}

		return [$type, (int)$entityId];
	}

	/**
	 * Parse parameters used in the `star` and `unstar` API methods
	 */
	private static function parseStarringParameters(array $ids, array $albumIds, array $artistIds) {
		// album IDs from newer clients
		$albumIds = \array_map('self::ripIdPrefix', $albumIds);

		// artist IDs from newer clients
		$artistIds = \array_map('self::ripIdPrefix', $artistIds);

		// Song IDs from newer clients and song/folder/album/artist IDs from older clients are all packed in $ids.
		// Also podcast IDs may come there; that is not documented part of the API but at least DSub does that.

		$trackIds = [];
		$channelIds = [];
		$episodeIds = [];

		foreach ($ids as $prefixedId) {
			$parts = \explode('-', $prefixedId);
			$type = $parts[0];
			$id = (int)$parts[1];

			if ($type == 'track') {
				$trackIds[] = $id;
			} elseif ($type == 'album') {
				$albumIds[] = $id;
			} elseif ($type == 'artist') {
				$artistIds[] = $id;
			} elseif ($type == 'podcast_channel') {
				$channelIds[] = $id;
			} elseif ($type == 'podcast_episode') {
				$episodeIds[] = $id;
			} elseif ($type == 'folder') {
				throw new SubsonicException('Starring folders is not supported', 0);
			} else {
				throw new SubsonicException("Unexpected ID format: $prefixedId", 0);
			}
		}

		return [
			'tracks' => $trackIds,
			'albums' => $albumIds,
			'artists' => $artistIds,
			'podcast_channels' => $channelIds,
			'podcast_episodes' => $episodeIds
		];
	}

	private function getFilesystemNode($id) {
		$rootFolder = $this->userMusicFolder->getFolder($this->userId);
		$nodes = $rootFolder->getById($id);

		if (\count($nodes) != 1) {
			throw new SubsonicException('file not found', 70);
		}

		return $nodes[0];
	}

	private function getIndexesForFolders() {
		$rootFolder = $this->userMusicFolder->getFolder($this->userId);

		return $this->subsonicResponse(['indexes' => ['index' => [
			['name' => '*',
			'artist' => [['id' => 'folder-' . $rootFolder->getId(), 'name' => $rootFolder->getName()]]]
		]]]);
	}

	private function getMusicDirectoryForFolder($id) {
		$folderId = self::ripIdPrefix($id); // get rid of 'folder-' prefix
		$folder = $this->getFilesystemNode($folderId);

		if (!($folder instanceof Folder)) {
			throw new SubsonicException("$id is not a valid folder", 70);
		}

		$nodes = $folder->getDirectoryListing();
		$subFolders = \array_filter($nodes, function ($n) {
			return $n instanceof Folder;
		});
		$tracks = $this->trackBusinessLayer->findAllByFolder($folderId, $this->userId);

		// A folder may contain thousands of audio files, and getting album data
		// for each of those individually would take a lot of time and great many DB queries.
		// To prevent having to do this in `trackToApi`, we fetch all the albums in one go.
		$this->albumBusinessLayer->injectAlbumsToTracks($tracks, $this->userId);

		$children = \array_merge(
			\array_map([$this, 'folderToApi'], $subFolders),
			\array_map([$this, 'trackToApi'], $tracks)
		);

		$content = [
			'directory' => [
				'id' => $id,
				'name' => $folder->getName(),
				'child' => $children
			]
		];

		// Parent folder ID is included if and only if the parent folder is not the top level
		$rootFolderId = $this->userMusicFolder->getFolder($this->userId)->getId();
		$parentFolderId = $folder->getParent()->getId();
		if ($rootFolderId != $parentFolderId) {
			$content['parent'] = 'folder-' . $parentFolderId;
		}

		return $this->subsonicResponse($content);
	}

	private function getIndexesForArtists($rootElementName = 'indexes') {
		$artists = $this->artistBusinessLayer->findAllHavingAlbums($this->userId, SortBy::Name);

		$indexes = [];
		foreach ($artists as $artist) {
			$indexes[$artist->getIndexingChar()][] = $this->artistToApi($artist);
		}

		$result = [];
		foreach ($indexes as $indexChar => $bucketArtists) {
			$result[] = ['name' => $indexChar, 'artist' => $bucketArtists];
		}

		return $this->subsonicResponse([$rootElementName => ['index' => $result]]);
	}

	private function getMusicDirectoryForArtist($id) {
		$artistId = self::ripIdPrefix($id); // get rid of 'artist-' prefix

		$artist = $this->artistBusinessLayer->find($artistId, $this->userId);
		$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $this->userId);

		return $this->subsonicResponse([
			'directory' => [
				'id' => $id,
				'name' => $artist->getNameString($this->l10n),
				'child' => \array_map([$this, 'albumToOldApi'], $albums)
			]
		]);
	}

	private function getMusicDirectoryForAlbum($id) {
		$albumId = self::ripIdPrefix($id); // get rid of 'album-' prefix

		$album = $this->albumBusinessLayer->find($albumId, $this->userId);
		$albumName = $album->getNameString($this->l10n);
		$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId);

		return $this->subsonicResponse([
			'directory' => [
				'id' => $id,
				'parent' => 'artist-' . $album->getAlbumArtistId(),
				'name' => $albumName,
				'child' => \array_map(function ($track) use ($album) {
					$track->setAlbum($album);
					return $this->trackToApi($track);
				}, $tracks)
			]
		]);
	}

	private function getMusicDirectoryForPodcastChannel($id) {
		$channelId = self::ripIdPrefix($id); // get rid of 'podcast_channel-' prefix
		$channel = $this->podcastService->getChannel($channelId, $this->userId, /*$includeEpisodes=*/ true);

		if ($channel === null) {
			throw new SubsonicException("Podcast channel $channelId not found", 0);
		}

		return $this->subsonicResponse([
			'directory' => [
				'id' => $id,
				'name' => $channel->getTitle(),
				'child' => Util::arrayMapMethod($channel->getEpisodes(), 'toSubsonicApi')
			]
		]);
	}

	/**
	 * @param Folder $folder
	 * @return array
	 */
	private function folderToApi($folder) {
		return [
			'id' => 'folder-' . $folder->getId(),
			'title' => $folder->getName(),
			'isDir' => true
		];
	}

	/**
	 * @param Artist $artist
	 * @return array
	 */
	private function artistToApi($artist) {
		$id = $artist->getId();
		$result = [
			'name' => $artist->getNameString($this->l10n),
			'id' => $id ? ('artist-' . $id) : '-1', // getArtistInfo may show artists without ID
			'albumCount' => $id ? $this->albumBusinessLayer->countByArtist($id) : 0
		];

		if (!empty($artist->getCoverFileId())) {
			$result['coverArt'] = $result['id'];
		}

		if (!empty($artist->getStarred())) {
			$result['starred'] = Util::formatZuluDateTime($artist->getStarred());
		}

		return $result;
	}

	/**
	 * The "old API" format is used e.g. in getMusicDirectory and getAlbumList
	 * @param Album $album
	 * @return array
	 */
	private function albumToOldApi($album) {
		$result = $this->albumCommonApiFields($album);

		$result['parent'] = 'artist-' . $album->getAlbumArtistId();
		$result['title'] = $album->getNameString($this->l10n);
		$result['isDir'] = true;

		return $result;
	}

	/**
	 * The "new API" format is used e.g. in getAlbum and getAlbumList2
	 * @param Album $album
	 * @return array
	 */
	private function albumToNewApi($album) {
		$result = $this->albumCommonApiFields($album);

		$result['artistId'] = 'artist-' . $album->getAlbumArtistId();
		$result['name'] = $album->getNameString($this->l10n);
		$result['songCount'] = $this->trackBusinessLayer->countByAlbum($album->getId());
		$result['duration'] = $this->trackBusinessLayer->totalDurationOfAlbum($album->getId());

		return $result;
	}

	private function albumCommonApiFields($album) {
		$result = [
			'id' => 'album-' . $album->getId(),
			'artist' => $album->getAlbumArtistNameString($this->l10n),
			'created' => Util::formatZuluDateTime($album->getCreated())
		];

		if (!empty($album->getCoverFileId())) {
			$result['coverArt'] = 'album-' . $album->getId();
		}

		if (!empty($album->getStarred())) {
			$result['starred'] = Util::formatZuluDateTime($album->getStarred());
		}

		if (!empty($album->getGenres())) {
			$result['genre'] = \implode(', ', \array_map(function (Genre $genre) {
				return $genre->getNameString($this->l10n);
			}, $album->getGenres()));
		}

		if (!empty($album->getYears())) {
			$result['year'] = $album->yearToAPI();
		}

		return $result;
	}

	/**
	 * The same API format is used both on "old" and "new" API methods. The "new" API adds some
	 * new fields for the songs, but providing some extra fields shouldn't be a problem for the
	 * older clients.
	 * @param Track $track If the track entity has no album references set, then it is automatically
	 *                     fetched from the AlbumBusinessLayer module.
	 * @return array
	 */
	private function trackToApi($track) {
		$albumId = $track->getAlbumId();

		$album = $track->getAlbum();
		if ($album === null && $albumId !== null) {
			$album = $this->albumBusinessLayer->findOrDefault($albumId, $this->userId);
			$track->setAlbum($album);
		}

		$result = [
			'id' => 'track-' . $track->getId(),
			'parent' => 'album-' . $albumId,
			//'discNumber' => $track->getDisk(), // not supported on any of the tested clients => adjust track number instead
			'title' => $track->getTitle() ?? '',
			'artist' => $track->getArtistNameString($this->l10n),
			'isDir' => false,
			'album' => $track->getAlbumNameString($this->l10n),
			'year' => $track->getYear(),
			'size' => $track->getSize() ?? 0,
			'contentType' => $track->getMimetype() ?? '',
			'suffix' => $track->getFileExtension(),
			'duration' => $track->getLength() ?: 0,
			'bitRate' => empty($track->getBitrate()) ? 0 : (int)\round($track->getBitrate()/1000), // convert bps to kbps
			//'path' => '',
			'isVideo' => false,
			'albumId' => 'album-' . $albumId,
			'artistId' => 'artist-' . $track->getArtistId(),
			'type' => 'music',
			'created' => Util::formatZuluDateTime($track->getCreated())
		];

		if ($album !== null && !empty($album->getCoverFileId())) {
			$result['coverArt'] = 'album-' . $album->getId();
		}

		$trackNumber = $track->getAdjustedTrackNumber();
		if ($trackNumber !== null) {
			$result['track'] = $trackNumber;
		}

		if (!empty($track->getStarred())) {
			$result['starred'] = Util::formatZuluDateTime($track->getStarred());
		}

		if (!empty($track->getGenreId())) {
			$result['genre'] = $track->getGenreNameString($this->l10n);
		}

		return $result;
	}

	/**
	 * @param Playlist $playlist
	 * @return array
	 */
	private function playlistToApi($playlist) {
		return [
			'id' => $playlist->getId(),
			'name' => $playlist->getName(),
			'owner' => $this->userId,
			'public' => false,
			'songCount' => $playlist->getTrackCount(),
			'duration' => $this->playlistBusinessLayer->getDuration($playlist->getId(), $this->userId),
			'comment' => $playlist->getComment() ?: '',
			'created' => Util::formatZuluDateTime($playlist->getCreated()),
			'changed' => Util::formatZuluDateTime($playlist->getUpdated())
			//'coverArt' => '' // added in API 1.11.0 but is optional even there
		];
	}

	/**
	 * Common logic for getAlbumList and getAlbumList2
	 * @return Album[]
	 */
	private function albumsForGetAlbumList(
			string $type, ?string $genre, ?int $fromYear, ?int $toYear, int $size, int $offset) : array {
		$size = \min($size, 500); // the API spec limits the maximum amount to 500

		$albums = [];

		switch ($type) {
			case 'random':
				$allAlbums = $this->albumBusinessLayer->findAll($this->userId);
				$indices = $this->random->getIndices(\count($allAlbums), $offset, $size, $this->userId, 'subsonic_albums');
				$albums = Util::arrayMultiGet($allAlbums, $indices);
				break;
			case 'starred':
				$albums = $this->albumBusinessLayer->findAllStarred($this->userId, $size, $offset);
				break;
			case 'alphabeticalByName':
				$albums = $this->albumBusinessLayer->findAll($this->userId, SortBy::Name, $size, $offset);
				break;
			case 'alphabeticalByArtist':
				$albums = $this->albumBusinessLayer->findAll($this->userId, SortBy::Parent, $size, $offset);
				break;
			case 'byGenre':
				self::ensureParamHasValue('genre', $genre);
				$albums = $this->findAlbumsByGenre($genre, $size, $offset);
				break;
			case 'byYear':
				self::ensureParamHasValue('fromYear', $fromYear);
				self::ensureParamHasValue('toYear', $toYear);
				$albums = $this->albumBusinessLayer->findAllByYearRange($fromYear, $toYear, $this->userId, $size, $offset);
				break;
			case 'newest':
				$albums = $this->albumBusinessLayer->findAll($this->userId, SortBy::Newest, $size, $offset);
				break;
			case 'highest':
			case 'frequent':
			case 'recent':
			default:
				$this->logger->log("Album list type '$type' is not supported", 'debug');
				break;
		}

		return $albums;
	}

	/**
	 * Common logic for getArtistInfo and getArtistInfo2
	 */
	private function doGetArtistInfo(string $rootName, string $id, bool $includeNotPresent) {
		$content = [];

		// This function may be called with a folder ID instead of an artist ID in case
		// the library is being browsed by folders. In that case, return an empty response.
		if (Util::startsWith($id, 'artist')) {
			$artistId = self::ripIdPrefix($id); // get rid of 'artist-' prefix

			$info = $this->lastfmService->getArtistInfo($artistId, $this->userId);

			if (isset($info['artist'])) {
				$content = [
					'biography' => $info['artist']['bio']['summary'],
					'lastFmUrl' => $info['artist']['url'],
					'musicBrainzId' => $info['artist']['mbid'] ?? null
				];

				$similarArtists = $this->lastfmService->getSimilarArtists($artistId, $this->userId, $includeNotPresent);
				$content['similarArtist'] = \array_map([$this, 'artistToApi'], $similarArtists);
			}

			$artist = $this->artistBusinessLayer->find($artistId, $this->userId);
			if ($artist->getCoverFileId() !== null) {
				$par = $this->request->getParams();
				$url = $this->urlGenerator->linkToRouteAbsolute('music.subsonic.handleRequest', ['method' => 'getCoverArt'])
						. "?u={$par['u']}&p={$par['p']}&v={$par['v']}&c={$par['c']}&id=$id";
				$content['largeImageUrl'] = [$url];
			}
		}

		// This method is unusual in how it uses non-attribute elements in the response. On the other hand,
		// all the details of the <similarArtist> elements are rendered as attributes. List those separately.
		$attributeKeys = ['name', 'id', 'albumCount', 'coverArt', 'starred'];

		return $this->subsonicResponse([$rootName => $content], $attributeKeys);
	}

	/**
	 * Common logic for getSimilarSongs and getSimilarSongs2
	 */
	private function doGetSimilarSongs(string $rootName, string $id, int $count) {
		if (Util::startsWith($id, 'artist')) {
			$artistId = self::ripIdPrefix($id);
		} elseif (Util::startsWith($id, 'album')) {
			$albumId = self::ripIdPrefix($id);
			$artistId = $this->albumBusinessLayer->find($albumId, $this->userId)->getAlbumArtistId();
		} elseif (Util::startsWith($id, 'track')) {
			$trackId = self::ripIdPrefix($id);
			$artistId = $this->trackBusinessLayer->find($trackId, $this->userId)->getArtistId();
		} else {
			throw new SubsonicException("Id $id has a type not supported on getSimilarSongs", 0);
		}

		$artists = $this->lastfmService->getSimilarArtists($artistId, $this->userId);
		$artists[] = $this->artistBusinessLayer->find($artistId, $this->userId);

		// Get all songs by the found artists
		$songs = [];
		foreach ($artists as $artist) {
			$songs = \array_merge($songs, $this->trackBusinessLayer->findAllByArtist($artist->getId(), $this->userId));
		}

		// Randomly select the desired number of songs
		$songs = $this->random->pickItems($songs, $count);

		return $this->subsonicResponse([$rootName => [
				'song' => \array_map([$this, 'trackToApi'], $songs)
		]]);
	}

	/**
	 * Common logic for search2 and search3
	 * @return array with keys 'artists', 'albums', and 'tracks'
	 */
	private function doSearch(string $query, int $artistCount, int $artistOffset,
			int $albumCount, int $albumOffset, int $songCount, int $songOffset) {

		if (empty($query)) {
			throw new SubsonicException("The 'query' argument is mandatory", 10);
		}

		return [
			'artists' => $this->artistBusinessLayer->findAllByName($query, $this->userId, true, $artistCount, $artistOffset),
			'albums' => $this->albumBusinessLayer->findAllByName($query, $this->userId, true, $albumCount, $albumOffset),
			'tracks' => $this->trackBusinessLayer->findAllByName($query, $this->userId, true, $songCount, $songOffset)
		];
	}

	/**
	 * Common logic for getStarred and getStarred2
	 * @return array
	 */
	private function doGetStarred() {
		return [
			'artists' => $this->artistBusinessLayer->findAllStarred($this->userId),
			'albums' => $this->albumBusinessLayer->findAllStarred($this->userId),
			'tracks' => $this->trackBusinessLayer->findAllStarred($this->userId)
		];
	}

	/**
	 * Common response building logic for search2, search3, getStarred, and getStarred2
	 * @param string $title Name of the main node in the response message
	 * @param array $results Search results with keys 'artists', 'albums', and 'tracks'
	 * @param boolean $useNewApi Set to true for search3 and getStarred2. There is a difference
	 *                           in the formatting of the album nodes.
	 * @return \OCP\AppFramework\Http\Response
	 */
	private function searchResponse($title, $results, $useNewApi) {
		$albumMapFunc = $useNewApi ? 'albumToNewApi' : 'albumToOldApi';

		return $this->subsonicResponse([$title => [
			'artist' => \array_map([$this, 'artistToApi'], $results['artists']),
			'album' => \array_map([$this, $albumMapFunc], $results['albums']),
			'song' => \array_map([$this, 'trackToApi'], $results['tracks'])
		]]);
	}

	/**
	 * Find tracks by genre name
	 * @param string $genreName
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Track[]
	 */
	private function findTracksByGenre($genreName, $limit=null, $offset=null) {
		$genre = $this->findGenreByName($genreName);

		if ($genre) {
			return $this->trackBusinessLayer->findAllByGenre($genre->getId(), $this->userId, $limit, $offset);
		} else {
			return [];
		}
	}

	/**
	 * Find albums by genre name
	 * @param string $genreName
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Album[]
	 */
	private function findAlbumsByGenre($genreName, $limit=null, $offset=null) {
		$genre = $this->findGenreByName($genreName);

		if ($genre) {
			return $this->albumBusinessLayer->findAllByGenre($genre->getId(), $this->userId, $limit, $offset);
		} else {
			return [];
		}
	}

	private function findGenreByName($name) {
		$genreArr = $this->genreBusinessLayer->findAllByName($name, $this->userId);
		if (\count($genreArr) == 0 && $name == Genre::unknownNameString($this->l10n)) {
			$genreArr = $this->genreBusinessLayer->findAllByName('', $this->userId);
		}
		return \count($genreArr) ? $genreArr[0] : null;
	}

	/**
	 * Given a prefixed ID like 'artist-123' or 'track-45', return just the numeric part.
	 */
	private static function ripIdPrefix(string $id) : int {
		return (int)(\explode('-', $id)[1]);
	}

	private function subsonicResponse($content, $useAttributes=true, $status = 'ok') {
		$content['status'] = $status;
		$content['version'] = self::API_VERSION;
		$responseData = ['subsonic-response' => $content];

		if ($this->format == 'json') {
			$response = new JSONResponse($responseData);
		} elseif ($this->format == 'jsonp') {
			$responseData = \json_encode($responseData);
			$response = new DataDisplayResponse("{$this->callback}($responseData);");
			$response->addHeader('Content-Type', 'text/javascript; charset=UTF-8');
		} else {
			if (\is_array($useAttributes)) {
				$useAttributes = \array_merge($useAttributes, ['status', 'version']);
			}
			$response = new XmlResponse($responseData, $useAttributes);
		}

		return $response;
	}

	public function subsonicErrorResponse($errorCode, $errorMessage) {
		return $this->subsonicResponse([
				'error' => [
					'code' => $errorCode,
					'message' => $errorMessage
				]
			], true, 'failed');
	}
}
