<?php
/**
 * Created by PhpStorm.
 * User: HOME
 * Date: 10.08.2018
 * Time: 16:32
 */

namespace Dawson\Youtube;

use Exception;
use Carbon\Carbon;
use Google_Client;
use Google_Service_YouTube;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class YoutubeExtra extends Youtube
{

    /**
     * Application Container
     *
     * @var Application
     */
    private $app;

    /**
     * Google Client
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * Google YouTube Service
     *
     * @var \Google_Service_YouTube
     */
    protected $youtube;

    /**
     * Video ID
     *
     * @var string
     */
    private $videoId;

    /**
     * Video Snippet
     *
     * @var array
     */
    private $snippet;

    /**
     * Thumbnail URL
     *
     * @var string
     */
    private $thumbnailUrl;

    public function __construct($app)
    {
        $this->app = $app;

        foreach ($this->app->config->get('youtube.accounts') as $account_id => $config) {

            $this->client[$account_id] = $this->setup($account_id, new Google_Client);

            $this->youtube[$account_id] = new Google_Service_YouTube($this->client[$account_id]);

            if ($accessToken[$account_id] = $this->getLatestAccessTokenFromDBWithAccount($account_id)) {

                $this->client[$account_id]->setAccessToken($accessToken[$account_id]);

            }

        }

    }

    public function uploadVideo($account_id, $path, array $data = [])
    {
        if(!file_exists($path)) {
            throw new Exception('Video file does not exist at path: "'. $path .'". Provide a full path to the file before attempting to upload.');
        }

        $this->handleAccessTokenWithAccount($account_id);

        try {
            $video = $this->getVideo($data);

            // Set the Chunk Size
            $chunkSize = 1 * 1024 * 1024;

            // Set the defer to true
            $this->client[$account_id]->setDefer(true);

            // Build the request
            $insert = $this->youtube[$account_id]->videos->insert('status,snippet', $video);

            // Upload
            $media = new \Google_Http_MediaFileUpload(
                $this->client[$account_id],
                $insert,
                'video/*',
                null,
                true,
                $chunkSize
            );

            // Set the Filesize
            $media->setFileSize(filesize($path));

            // Read the file and upload in chunks
            $status = false;
            $handle = fopen($path, "rb");

            while (!$status && !feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $status = $media->nextChunk($chunk);
            }

            fclose($handle);

            $this->client->setDefer(false);

            // Set ID of the Uploaded Video
            $this->videoId = $status['id'];

            // Set the Snippet from Uploaded Video
            $this->snippet = $status['snippet'];

        }  catch (\Google_Service_Exception $e) {
            throw new Exception($e->getMessage());
        } catch (\Google_Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $this;
    }

    public function updateVideo($account_id, $id, array $data = [])
    {
        $this->handleAccessTokenWithAccount($account_id);

        if (!$this->existsWithAccount($account_id, $id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        try {
            $video = $this->getVideo($data, $id);

            $status = $this->youtube[$account_id]->videos->update('status,snippet', $video);

            // Set ID of the Updated Video
            $this->videoId = $status['id'];

            // Set the Snippet from Updated Video
            $this->snippet = $status['snippet'];

        }  catch (\Google_Service_Exception $e) {

            throw new Exception($e->getMessage());

        } catch (\Google_Exception $e) {

            throw new Exception($e->getMessage());

        }

        return $this;
    }

    /**
     * @param $data
     * @param null $id
     * @return \Google_Service_YouTube_Video
     */
    private function getVideo($data, $id = null)
    {

        $video = new \Google_Service_YouTube_Video();

        if ($id) {
            $video->setId($id);
        }

        if (array_key_exists('snippet', $data)) {

            $snippet = new \Google_Service_YouTube_VideoSnippet();

            if (array_key_exists('title', $data['snippet'])) {
                $snippet->setTitle($data['snippet']['title']);
            }
            if (array_key_exists('description', $data['snippet'])) {
                $snippet->setDescription($data['snippet']['description']);
            }
            if (array_key_exists('tags', $data['snippet'])) {
                $snippet->setTags($data['snippet']['tags']);
            }
            if (array_key_exists('categoryId', $data['snippet'])) {
                $snippet->setCategoryId($data['snippet']['categoryId']);
            }

            $video->setSnippet($snippet);

        }

        if (array_key_exists('status', $data)) {

            $status = new \Google_Service_YouTube_VideoStatus();

            if (array_key_exists('privacyStatus', $data['status'])) {
                $status->setPrivacyStatus($data['status']['privacyStatus']);
            }
            if (array_key_exists('embeddable', $data['status'])) {
                $status->setEmbeddable($data['status']['embeddable']);
            }

            $video->setStatus($status);

        }

        return $video;

    }

    /**
     * Check if a YouTube video exists by it's ID.
     *
     * @param  int  $id
     *
     * @return bool
     */
    public function existsWithAccount($account_id, $id)
    {
        $this->handleAccessTokenWithAccount($account_id);

        $response = $this->youtube[$account_id]->videos->listVideos('status', ['id' => $id]);

        if (empty($response->items)) return false;

        return true;
    }

    /**
     * Setup the Google Client
     *
     * @param Google_Client $client
     * @return Google_Client $client
     * @throws Exception
     */
    private function setup($account_id, Google_Client $client)
    {

        if(
            !$this->app->config->get('youtube.accounts.'.$account_id.'.client_id') ||
            !$this->app->config->get('youtube.accounts.'.$account_id.'.client_secret')
        ) {
            throw new Exception('A Google "client_id" and "client_secret" must be configured.');
        }

        $client->setClientId($this->app->config->get('youtube.accounts.'.$account_id.'.client_id'));
        $client->setClientSecret($this->app->config->get('youtube.accounts.'.$account_id.'.client_secret'));
        $client->setScopes($this->app->config->get('youtube.accounts.'.$account_id.'.scopes'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $client->setRedirectUri(url(
            $this->app->config->get('youtube.accounts.'.$account_id.'.routes.prefix')
            . '/' .
            $this->app->config->get('youtube.accounts.'.$account_id.'.routes.redirect_uri')
        ));

        return $client;
    }

    /**
     * Saves the access token to the database.
     *
     * @param  string  $accessToken
     */
    public function saveAccessTokenToDBWithAccount($account_id, $accessToken)
    {
        return Storage::put($account_id . '_YOUTUBE_ACCESS', json_encode($accessToken), 'private');
    }

    /**
     * Get the latest access token from the database.
     *
     * @return string
     */
    public function getLatestAccessTokenFromDBWithAccount($account_id)
    {

        try {
            $access = Storage::get($account_id . '_YOUTUBE_ACCESS');
            $access = json_decode($access, true);
        } catch (\Exception $e) {
            return null;
        }

        return $access ?? null;

    }

    /**
     * Handle the Access Token
     *
     * @return void
     */
    public function handleAccessTokenWithAccount($account_id)
    {

        if (is_null($accessToken[$account_id] = $this->client[$account_id]->getAccessToken())) {
            throw new \Exception('An access token is required.');
        }

        if ($this->client[$account_id]->isAccessTokenExpired()) {

            // If we have a "refresh_token"
            if (array_key_exists('refresh_token', $accessToken[$account_id])) {

                // Refresh the access token
                $this->client[$account_id]->refreshToken($accessToken[$account_id]['refresh_token']);

                // Save the access token
                $this->saveAccessTokenToDBWithAccount($account_id, $this->client[$account_id]->getAccessToken());

            }

        }

    }

    public function createAuthUrl($account_id)
    {
        return $this->client[$account_id]->createAuthUrl();
    }

    public function authenticate($account_id, $code)
    {
        return $this->client[$account_id]->authenticate($code);
    }





    /**
     * Get items in a playlist by playlist ID, return an array of PHP objects
     *
     * @param string $playlistId
     * @param string $pageToken
     * @param integer $maxResults
     * @param array $part
     * @return array
     * @throws \Exception
     */
    public function getPlaylistItemsByPlaylistId(
        $account_id,
        $playlistId,
        $pageToken = '',
        $maxResults = 50,
        $part = ['id', 'snippet', 'contentDetails', 'status']
    )
    {

        $this->handleAccessTokenWithAccount($account_id);

        try {

            $apiData = $this->youtube[$account_id]->playlistItems->listPlaylistItems(
                implode(', ', $part),
                [
                    'maxResults' => $maxResults,
                    'playlistId' => $playlistId,
                    'pageToken' => $pageToken
                ]
            );

        } catch (\Google_Service_Exception $e) {

            throw new Exception($e->getMessage());

        } catch (\Google_Exception $e) {

            throw new Exception($e->getMessage());

        }

        $result = ['results' => $this->decodeList($apiData)];
        $result['info']['totalResults'] =  (isset($this->page_info['totalResults']) ? $this->page_info['totalResults'] : 0);
        $result['info']['nextPageToken'] = (isset($this->page_info['nextPageToken']) ? $this->page_info['nextPageToken'] : false);
        $result['info']['prevPageToken'] = (isset($this->page_info['prevPageToken']) ? $this->page_info['prevPageToken'] : false);

        return $result;
    }

    public $page_info = [];

    /**
     * Decode the response from youtube, extract the list of resource objects
     *
     * @param  string $apiData response string from youtube
     * @throws \Exception
     * @return array Array of StdClass objects
     */
    public function decodeList(&$apiData)
    {
        $resObj = $apiData;
        if (isset($resObj->error)) {
            $msg = "Error " . $resObj->error->code . " " . $resObj->error->message;
            if (isset($resObj->error->errors[0])) {
                $msg .= " : " . $resObj->error->errors[0]->reason;
            }
            throw new \Exception($msg);
        } else {
            $this->page_info = [
                'resultsPerPage' => $resObj->pageInfo->resultsPerPage,
                'totalResults' => $resObj->pageInfo->totalResults,
                'kind' => $resObj->kind,
                'etag' => $resObj->etag,
                'prevPageToken' => null,
                'nextPageToken' => null,
            ];
            if (isset($resObj->prevPageToken)) {
                $this->page_info['prevPageToken'] = $resObj->prevPageToken;
            }
            if (isset($resObj->nextPageToken)) {
                $this->page_info['nextPageToken'] = $resObj->nextPageToken;
            }
            $itemsArray = $resObj->items;
            if (!is_array($itemsArray) || count($itemsArray) == 0) {
                return false;
            } else {
                return $itemsArray;
            }
        }
    }



}
