<?php
/**
 * Mux video processing / streaming client.
 *
 * API reference: https://docs.mux.com/api-reference/video
 * Credentials (HTTP Basic) are read from MUX_TOKEN_ID and MUX_SECRET_KEY.
 * Processing status is read from the API; no webhook secret is needed.
 */
class MuxClient
{
    const API_BASE = 'https://api.mux.com/video/v1';

    protected $tokenId;
    protected $tokenSecret;
    protected $timeout;

    public function __construct($tokenId = null, $tokenSecret = null, $timeout = 900)
    {
        $this->tokenId     = $tokenId ?: getenv('MUX_TOKEN_ID');
        $this->tokenSecret = $tokenSecret ?: getenv('MUX_SECRET_KEY');
        $this->timeout     = (int) $timeout;

        if (empty($this->tokenId) || empty($this->tokenSecret)) {
            throw new RuntimeException('MUX_TOKEN_ID / MUX_SECRET_KEY are not configured');
        }
    }

    /**
     * Create a one-time upload URL. The file is streamed straight to Mux,
     * so it never has to pass through a second PHP request.
     *
     * @return array{upload_id:string,upload_url:string,asset_id:?string}
     */
    public function createDirectUpload(array $assetSettings = null)
    {
        $response = $this->request('POST', '/uploads', array(
            'cors_origin'        => '*',
            'timeout'            => 3600,
            'new_asset_settings' => $assetSettings ?: array('playback_policy' => array('public')),
        ));

        $data = isset($response['data']) ? $response['data'] : array();

        if (empty($data['url'])) {
            throw new RuntimeException('Mux did not return an upload URL');
        }

        return array(
            'upload_id'  => isset($data['id']) ? $data['id'] : '',
            'upload_url' => $data['url'],
            'asset_id'   => isset($data['asset_id']) ? $data['asset_id'] : null,
        );
    }

    /**
     * PUT a local file to a Mux direct-upload URL.
     */
    public function uploadFileToUrl($uploadUrl, $filePath)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Video file is not readable');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the video file');
        }

        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, array(
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => filesize($filePath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/octet-stream'),
        ));

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Mux upload failed: ' . ($error ?: 'HTTP ' . $status));
        }

        return json_decode($raw, true);
    }

    /**
     * Create an asset from a publicly reachable video file URL.
     */
    public function createAssetFromUrl($url, array $playbackPolicy = array('public'))
    {
        $response = $this->request('POST', '/assets', array(
            'input'           => array(array('url' => $url)),
            'playback_policy' => $playbackPolicy,
        ));

        return $this->formatAsset($response);
    }

    public function getAsset($assetId)
    {
        return $this->formatAsset($this->request('GET', '/assets/' . rawurlencode($assetId)));
    }

    public function getUpload($uploadId)
    {
        $response = $this->request('GET', '/uploads/' . rawurlencode($uploadId));
        $data     = isset($response['data']) ? $response['data'] : array();

        return array(
            'upload_id' => isset($data['id']) ? $data['id'] : '',
            'asset_id'  => isset($data['asset_id']) ? $data['asset_id'] : null,
            'status'    => isset($data['status']) ? $data['status'] : '',
        );
    }

    public function deleteAsset($assetId)
    {
        return $this->request('DELETE', '/assets/' . rawurlencode($assetId));
    }

    public static function playbackUrl($playbackId)
    {
        return 'https://stream.mux.com/' . $playbackId . '.m3u8';
    }

    public static function thumbnailUrl($playbackId, $time = 1)
    {
        return 'https://image.mux.com/' . $playbackId . '/thumbnail.jpg?time=' . (int) $time;
    }

    protected function formatAsset(array $response)
    {
        $data = isset($response['data']) ? $response['data'] : array();

        return array(
            'asset_id'    => isset($data['id']) ? $data['id'] : '',
            'status'      => isset($data['status']) ? $data['status'] : '',
            'playback_id' => !empty($data['playback_ids'][0]['id']) ? $data['playback_ids'][0]['id'] : '',
            'duration'    => isset($data['duration']) ? $data['duration'] : null,
            'errors'      => isset($data['errors']) ? $data['errors'] : null,
        );
    }

    protected function request($method, $path, $body = null)
    {
        $ch = curl_init(self::API_BASE . $path);

        $headers = array(
            'Accept: application/json',
            'Content-Type: application/json',
        );

        $curlOptions = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $this->tokenId . ':' . $this->tokenSecret,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => $this->timeout,
        );

        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $curlOptions);

        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Mux request failed: ' . $curlErr);
        }

        $data = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && !empty($data['error']['messages'][0])
                ? $data['error']['messages'][0]
                : 'HTTP ' . $status;
            throw new RuntimeException('Mux API error: ' . $message);
        }

        return is_array($data) ? $data : array();
    }
}
