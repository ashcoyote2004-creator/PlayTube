<?php
/**
 * Vidara.so storage client.
 *
 * API reference: https://vidara.so/api — authentication is the `api_key`
 * query parameter (and the `key` field on the upload server).
 * The key is read from the VIDARA_API_KEY environment variable; it is never
 * written into the codebase or sent to the browser.
 */
class VidaraClient
{
    const API_BASE              = 'https://api.vidara.so/v1';
    const DEFAULT_UPLOAD_SERVER = 'https://s1.vidara.so/api/upload';

    protected $apiKey;
    protected $timeout;

    public function __construct($apiKey = null, $timeout = 1800)
    {
        $this->apiKey  = $apiKey ?: getenv('VIDARA_API_KEY');
        $this->timeout = (int) $timeout;

        if (empty($this->apiKey)) {
            throw new RuntimeException('VIDARA_API_KEY is not configured');
        }
    }

    public function getAccountInfo()
    {
        return $this->get('/user/info');
    }

    public function getUploadServer()
    {
        $response = $this->get('/upload/server');

        if (!empty($response['result']['upload_server'])) {
            return $response['result']['upload_server'];
        }

        return self::DEFAULT_UPLOAD_SERVER;
    }

    /**
     * Upload a local video file to Vidara.
     *
     * @return array{filecode:string,url:string,title:string,video_id:mixed}
     */
    public function uploadFile($filePath, $title = null)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Video file is not readable');
        }

        $filename = $title ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $response = $this->request('POST', $this->getUploadServer(), array(
            'post' => array(
                'key'  => $this->apiKey,
                'file' => new CURLFile($filePath, $mimeType, $filename),
            ),
        ));

        if (empty($response['filecode'])) {
            throw new RuntimeException('Vidara upload did not return a file code');
        }

        return array(
            'filecode' => $response['filecode'],
            'url'      => isset($response['url']) ? $response['url'] : '',
            'title'    => isset($response['title']) ? $response['title'] : $filename,
            'video_id' => isset($response['video_id']) ? $response['video_id'] : null,
        );
    }

    /**
     * Ask Vidara to pull a video from a direct file URL and store it.
     */
    public function uploadFromUrl($url)
    {
        $response = $this->get('/upload/url', array('url' => $url));

        if (empty($response['result'][0]['filecode'])) {
            throw new RuntimeException('Vidara did not accept the remote URL');
        }

        return $response['result'][0];
    }

    public function getFileInfo($filecode)
    {
        $response = $this->get('/video/info', array('filecode' => $filecode));

        if (empty($response['result'][0])) {
            throw new RuntimeException('Vidara video was not found');
        }

        return $response['result'][0];
    }

    /**
     * Encoding progress for one video. `progress_percentage` reaches 100%
     * when Vidara has finished preparing the file.
     */
    public function getEncodingStatus($filecode)
    {
        $response = $this->get('/video/status', array('filecode' => $filecode));

        return isset($response['result']) ? $response['result'] : array();
    }

    public function uploadThumbnail($filecode, $thumbUrl)
    {
        return $this->get('/upload/thumb', array(
            'filecode'  => $filecode,
            'thumb_url' => $thumbUrl,
        ));
    }

    public function deleteFile($filecode)
    {
        return $this->get('/video/delete', array('filecode' => $filecode));
    }

    protected function get($path, array $params = array())
    {
        $params['api_key'] = $this->apiKey;

        return $this->request('GET', self::API_BASE . $path . '?' . http_build_query($params));
    }

    protected function request($method, $url, array $options = array())
    {
        $ch = curl_init($url);

        $curlOptions = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => $this->timeout,
        );

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            if (isset($options['post'])) {
                $curlOptions[CURLOPT_POSTFIELDS] = $options['post'];
            }
        }

        curl_setopt_array($ch, $curlOptions);

        $raw      = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Vidara request failed: ' . $curlErr);
        }

        $data = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && !empty($data['msg']) ? $data['msg'] : 'HTTP ' . $status;
            throw new RuntimeException('Vidara API error: ' . $message);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Vidara returned an unexpected response');
        }

        return $data;
    }
}
