<?php
/**
 * Created by PhpStorm.
 * User: HOME
 * Date: 10.08.2018
 * Time: 16:32
 */

namespace Dawson\Youtube;


class YoutubeExtra extends Youtube
{

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

    public $page_info = [];

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

    public function upload($path, array $data = [], $privacyStatus = 'public')
    {
        return $this->uploadVideo($path, $data);
    }
    public function uploadVideo($path, array $data = [])
    {
        if(!file_exists($path)) {
            throw new Exception('Video file does not exist at path: "'. $path .'". Provide a full path to the file before attempting to upload.');
        }

        $this->handleAccessToken();

        try {
            $video = $this->getVideo($data);

            // Set the Chunk Size
            $chunkSize = 1 * 1024 * 1024;

            // Set the defer to true
            $this->client->setDefer(true);

            // Build the request
            $insert = $this->youtube->videos->insert('status,snippet', $video);

            // Upload
            $media = new \Google_Http_MediaFileUpload(
                $this->client,
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

    public function update($id, array $data = [], $privacyStatus = 'public')
    {
        return $this->updateVideo($id, $data);
    }
    public function updateVideo($id, array $data = [])
    {
        $this->handleAccessToken();

        if (!$this->exists($id)) {
            throw new Exception('A video matching id "'. $id .'" could not be found.');
        }

        try {
            $video = $this->getVideo($data, $id);

            $status = $this->youtube->videos->update('status,snippet', $video);

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
        $playlistId,
        $pageToken = '',
        $maxResults = 50,
        $part = ['id', 'snippet', 'contentDetails', 'status']
    )
    {

        $this->handleAccessToken();

        try {

            $apiData = $this->youtube->playlistItems->listPlaylistItems(
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
