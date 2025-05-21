<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Logger.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'EncryptionHelper.php';

/**
 * Handles interactions with Google Drive API (v3) for file operations.
 */
class GoogleDriveAPI extends EncryptionHelper
{
    private const AUTH_URL = 'https://oauth2.googleapis.com/token';
    private const API_URL = 'https://www.googleapis.com';
    private const TOKEN_FILE_HEADER = "<?php defined('GDBPATH') || die('forbidden'); // ";
    private const RATE_LIMIT_INTERVAL = 0.1;

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $authCode;
    private string $encryptionPassword;
    private string $encryptionKey;
    private ?string $refreshToken = null;
    private ?string $accessToken = null;

    /**
     * Initializes the Google Drive API client.
     *
     * @param string $clientId OAuth Client ID.
     * @param string $clientSecret OAuth Client Secret.
     * @param string $redirectUri Redirect URI for OAuth.
     * @param string $authCode Authorization code from OAuth flow.
     * @param string $encryptionPassword Password for token encryption.
     * @param string $encryptionKey Non-empty string key for token encryption.
     * @throws InvalidArgumentException If required arguments are missing or invalid.
     */
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $authCode,
        string $encryptionPassword,
        string $encryptionKey
    ) {
        $missingArgs = array_filter([
            empty($clientId) ? 'clientId' : null,
            empty($clientSecret) ? 'clientSecret' : null,
            empty($redirectUri) ? 'redirectUri' : null,
            empty($authCode) ? 'authCode' : null,
            empty($encryptionPassword) ? 'encryptionPassword' : null,
            empty($encryptionKey) ? 'encryptionKey' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(
                sprintf('Missing required arguments: %s', implode(', ', $missingArgs))
            );
        }

        if (!preg_match('/^[0-9]+-[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com$/', $clientId)) {
            throw new InvalidArgumentException('Invalid Client ID format.');
        }

        if (!preg_match('/^GOCSPX-[a-zA-Z0-9_-]+$/', $clientSecret)) {
            throw new InvalidArgumentException('Invalid Client Secret format.');
        }

        if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid Redirect URI format.');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->authCode = $authCode;
        $this->encryptionPassword = $encryptionPassword;
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * Enforces rate limiting to avoid Google Drive API quotas.
     */
    private function rateLimit(): void
    {
        static $lastRequestTime = 0;
        $currentTime = microtime(true);
        if ($currentTime - $lastRequestTime < self::RATE_LIMIT_INTERVAL) {
            usleep((int) ((self::RATE_LIMIT_INTERVAL - ($currentTime - $lastRequestTime)) * 1000000));
        }
        $lastRequestTime = microtime(true);
    }

    /**
     * Performs a cURL request to the Google API.
     *
     * @param string $url API endpoint URL.
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param array<string, string> $headers HTTP headers.
     * @param mixed $postData Data for POST/PUT requests.
     * @param bool $returnHeaders Whether to return response headers.
     * @return array{status: int, body: string, headers?: string} Response data.
     * @throws RuntimeException If the request fails.
     */
    private function makeCurlRequest(
        string $url,
        string $method,
        array $headers,
        ?string $context = null,
        $postData = null,
        bool $returnHeaders = false
    ): array {
        $this->rateLimit();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADER, $returnHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($postData) ? $postData : json_encode($postData));
            }
        }

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new RuntimeException("$context: [GoogleDriveAPI::makeCurlRequest] cURL request failed: $error");
        }

        return [
            'status' => $status,
            'body' => $result,
            'headers' => $returnHeaders ? $result : null,
        ];
    }

    /**
     * Validates API response and throws an exception on failure.
     *
     * @param array{status: int, body: string, headers?: string} $response cURL response.
     * @param string $context Error context (e.g., "Failed to search").
     * @param int $expectedStatus Expected HTTP status code.
     * @param string $expectedType Expected response type. ('array' | 'null') Defaults to 'array'.
     * @return array Decoded JSON response.
     * @throws RuntimeException If the response indicates failure.
     */
    private function validateApiResponse(array $response, string $context, int $expectedStatus, string $expectedType = 'array'): array|null
    {
        $jsonResult = json_decode($response['body'], true);

        if ($response['status'] !== $expectedStatus || !empty($jsonResult['error'])) {
            throw new RuntimeException(sprintf(
                '%s (HTTP %d): %s',
                $context,
                $response['status'],
                $jsonResult['error']['message'] ?? $jsonResult['error_description'] ?? "Response: {$response['body']}"
            ));
        }

        if (strtolower(gettype($jsonResult)) !== strtolower($expectedType)) {
            throw new RuntimeException(sprintf(
                '%s: Invalid response type: %s',
                $context,
                gettype($jsonResult)
            ));
        }

        return $jsonResult;
    }

    /**
     * Retrieves or generates a refresh token.
     *
     * The token is stored in .refresh-token.php with a header:
     * "<?php defined('GDBPATH') || die('forbidden'); // [$timestamp] ".
     * The header is skipped to extract the encrypted token.
     * If the file is unreadable or invalid, a new token is requested.
     *
     * @throws RuntimeException If token retrieval or storage fails.
     */
    public function getRefreshToken(): void
    {
        if (!empty($this->refreshToken)) {
            return;
        }

        // Try to read from file
        $tokenFile = GDBPATH . '.refresh-token.php';
        if (file_exists($tokenFile) && is_readable($tokenFile)) {
            $content = file_get_contents($tokenFile);
            if ($content === false) {
                throw new RuntimeException("[GoogleDriveAPI::getRefreshToken] Failed to read refresh token file: $tokenFile");
            }

            if (strpos($content, self::TOKEN_FILE_HEADER . '[') === 0) {
                $tokenStart = strpos($content, '] ') + 2;
                if ($tokenStart !== false && $tokenStart < strlen($content)) {
                    $encryptedToken = substr($content, $tokenStart);
                    try {
                        $this->refreshToken = $this->decryptData(
                            $encryptedToken,
                            $this->encryptionPassword,
                            $this->encryptionKey
                        );
                        return;
                    } catch (RuntimeException $e) {
                        Logger::warning("[GoogleDriveAPI::getRefreshToken] Failed to decrypt refresh token: {$e->getMessage()}");
                    }
                } else {
                    Logger::warning("[GoogleDriveAPI::getRefreshToken] Invalid refresh token file format: $tokenFile");
                }
            } else {
                Logger::warning("[GoogleDriveAPI::getRefreshToken] Invalid header in refresh token file: $tokenFile");
            }
        }

        // Request new refresh token
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $postData = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $this->authCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);

        $response = $this->makeCurlRequest(self::AUTH_URL, 'POST', $headers, '[GoogleDriveAPI::getRefreshToken] Failed to obtain refresh token', $postData);

        if ($response['status'] === 400) {
            throw new RuntimeException(
                '[GoogleDriveAPI::getRefreshToken] Invalid or expired authorization code. Please obtain a new authorization code and update $authCode in config.php.'
            );
        }

        $jsonResult = $this->validateApiResponse($response, '[GoogleDriveAPI::getRefreshToken] Failed to obtain refresh token', 200);

        if (empty($jsonResult['refresh_token'])) {
            throw new RuntimeException('[GoogleDriveAPI::getRefreshToken] Refresh token not found in response. Response: ' . $response['body']);
        }

        $this->refreshToken = $jsonResult['refresh_token'];

        // Store encrypted token
        try {
            $encryptedToken = $this->encryptData(
                $this->refreshToken,
                $this->encryptionPassword,
                $this->encryptionKey
            );

            if (!is_writable(GDBPATH)) {
                throw new RuntimeException('[GoogleDriveAPI::getRefreshToken] Directory not writable: ' . GDBPATH);
            }

            $timestamp = date('Y-m-d H:i:s');
            $tokenFileContent = self::TOKEN_FILE_HEADER . "[$timestamp] $encryptedToken";
            if (file_put_contents($tokenFile, $tokenFileContent) === false) {
                throw new RuntimeException("[GoogleDriveAPI::getRefreshToken] Failed to write refresh token file: $tokenFile");
            }

            chmod($tokenFile, 0600);
        } catch (RuntimeException $e) {
            Logger::warning("[GoogleDriveAPI::getRefreshToken] Failed to store refresh token in $tokenFile: {$e->getMessage()}");
        }
    }

    /**
     * Retrieves an access token using the refresh token.
     *
     * @throws RuntimeException If token retrieval fails.
     */
    public function getAccessToken(): void
    {
        if (!empty($this->accessToken)) {
            return;
        }

        $this->getRefreshToken();

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $postData = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->redirectUri,
        ]);

        $response = $this->makeCurlRequest(self::AUTH_URL, 'POST', $headers, '[GoogleDriveAPI::getAccessToken] Failed to obtain access token', $postData);

        if (in_array($response['status'], [401, 403])) {
            throw new RuntimeException(
                '[GoogleDriveAPI::getAccessToken] Invalid or expired refresh token. Please obtain a new authorization code and update $authCode in config.php.'
            );
        }

        $jsonResult = $this->validateApiResponse($response, '[GoogleDriveAPI::getAccessToken] Failed to obtain access token', 200);

        if (empty($jsonResult['access_token'])) {
            throw new RuntimeException('[GoogleDriveAPI::getAccessToken] Access token not found in response. Response: ' . $response['body']);
        }

        $this->accessToken = $jsonResult['access_token'];
    }

    /**
     * Searches for files or folders in Google Drive.
     *
     * @param string|null $rootId Parent folder ID.
     * @param string|null $name File or folder name.
     * @param string|null $type 'file' or 'folder'.
     * @param string|null $dateAfter Items created after this date (Y-m-d\TH:i:s).
     * @param string|null $dateBefore Items created before this date (Y-m-d\TH:i:s).
     * @return string[] Array of file/folder IDs.
     * @throws InvalidArgumentException If type is invalid.
     * @throws RuntimeException If the search fails.
     */
    public function search(
        ?string $rootId = null,
        ?string $name = null,
        ?string $type = null,
        ?string $dateAfter = null,
        ?string $dateBefore = null
    ): array {
        if (!$rootId && !$name && !$type && !$dateAfter && !$dateBefore) {
            return [];
        }

        $this->getAccessToken();

        $query = [];

        if ($rootId) {
            $query[] = "'$rootId' in parents";
        }

        if ($name) {
            $sanitizedName = str_replace("'", "\\'", $name);
            $query[] = "name = '$sanitizedName'";
        }

        if ($type) {
            $query[] = match ($type) {
                'file' => "mimeType != 'application/vnd.google-apps.folder'",
                'folder' => "mimeType = 'application/vnd.google-apps.folder'",
                default => throw new InvalidArgumentException("[GoogleDriveAPI::search] Invalid type: $type"),
            };
        }

        if ($dateAfter) {
            $query[] = "createdTime > '$dateAfter'";
        }

        if ($dateBefore) {
            $query[] = "createdTime < '$dateBefore'";
        }

        $query[] = 'trashed = false';

        $searchQuery = urlencode(implode(' and ', $query));
        $url = self::API_URL . "/drive/v3/files?q=$searchQuery";

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer $this->accessToken",
        ];

        $response = $this->makeCurlRequest($url, 'GET', $headers, "[GoogleDriveAPI::search] Search failed for query \"$searchQuery\"");
        $jsonResult = $this->validateApiResponse($response, "[GoogleDriveAPI::search] Search failed for query \"$searchQuery\"", 200);

        if (!empty($jsonResult['incompleteSearch'])) {
            Logger::warning('[GoogleDriveAPI::search] Incomplete search results returned.');
        }

        return array_column($jsonResult['files'] ?? [], 'id');
    }

    /**
     * Deletes files or folders from Google Drive.
     *
     * @param string[] $fileIds Array of file/folder IDs to delete.
     * @throws InvalidArgumentException If fileIds is invalid.
     * @throws RuntimeException If deletion fails.
     */
    public function delete(array $fileIds): void
    {
        if (empty($fileIds) || !is_array($fileIds)) {
            throw new InvalidArgumentException('[GoogleDriveAPI::delete] File ID list is empty.');
        }

        $this->getAccessToken();

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer $this->accessToken",
        ];

        foreach ($fileIds as $fileId) {
            if (empty($fileId)) {
                throw new InvalidArgumentException('[GoogleDriveAPI::delete] Invalid file ID.');
            }

            $url = self::API_URL . "/drive/v3/files/$fileId";
            $response = $this->makeCurlRequest($url, 'DELETE', $headers, "[GoogleDriveAPI::delete] Failed to delete file ID $fileId");

            if ($response['status'] !== 204) {
                $jsonResult = json_decode($response['body'], true);

                throw new RuntimeException(sprintf(
                    '[GoogleDriveAPI::delete] Failed to delete file ID %s (HTTP %d): %s',
                    $fileId,
                    $response['status'],
                    $jsonResult['error']['message'] ?? "Response: {$response['body']}"
                ));
            }
        }
    }

    /**
     * Creates a folder in Google Drive.
     *
     * @param string $name Folder name.
     * @param string|null $rootId Parent folder ID.
     * @return string Folder ID.
     * @throws InvalidArgumentException If name is empty.
     * @throws RuntimeException If folder creation fails.
     */
    public function createFolder(string $name, ?string $rootId = null): string
    {
        if (empty($name)) {
            throw new InvalidArgumentException('[GoogleDriveAPI::createFolder] Folder name is required.');
        }

        $existing = $this->search($rootId, $name, 'folder');
        if (!empty($existing)) {
            return $existing[0];
        }

        $this->getAccessToken();

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer $this->accessToken",
        ];

        $postData = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        if ($rootId) {
            $postData['parents'] = [$rootId];
        }

        $url = self::API_URL . '/drive/v3/files';
        $response = $this->makeCurlRequest($url, 'POST', $headers, "[GoogleDriveAPI::createFolder] Failed to create folder \"$name\"", $postData);
        $jsonResult = $this->validateApiResponse($response, "[GoogleDriveAPI::createFolder] Failed to create folder \"$name\"", 200);

        if (empty($jsonResult['id'])) {
            throw new RuntimeException('[GoogleDriveAPI::createFolder] Folder ID not found in response. Response: ' . $response['body']);
        }

        return $jsonResult['id'];
    }

    /**
     * Uploads a file to Google Drive.
     *
     * @param string $name File name.
     * @param string|null $path Local file path.
     * @param string|null $data Raw file data.
     * @param string|null $rootId Parent folder ID.
     * @param bool $override Overwrite existing file.
     * @return string File ID.
     * @throws InvalidArgumentException If arguments are invalid.
     * @throws RuntimeException If upload fails.
     */
    public function uploadFile(
        string $name,
        ?string $path = null,
        ?string $data = null,
        ?string $rootId = null,
        bool $override = true
    ): string {
        if (empty($name)) {
            throw new InvalidArgumentException('[GoogleDriveAPI::uploadFile] File name is required.');
        }

        if (!$data && !$path) {
            throw new InvalidArgumentException('[GoogleDriveAPI::uploadFile] Either path or data must be provided.');
        }

        $fileData = $data;

        if ($path) {
            $realPath = realpath($path);
            if ($realPath === false) {
                throw new RuntimeException("[GoogleDriveAPI::uploadFile] File does not exist: $path");
            }

            $fileData = file_get_contents($realPath);
            if ($fileData === false) {
                throw new RuntimeException("[GoogleDriveAPI::uploadFile] Failed to read file: $path");
            }
        }

        if (!$override) {
            $existing = $this->search($rootId, $name, 'file');
            if (!empty($existing)) {
                return $existing[0];
            }
        }

        $this->getAccessToken();

        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer $this->accessToken",
        ];

        $postData = [
            'name' => $name,
        ];

        if ($rootId) {
            $postData['parents'] = [$rootId];
        }

        $url = self::API_URL . '/upload/drive/v3/files?uploadType=resumable';
        $response = $this->makeCurlRequest($url, 'POST', $headers, "[GoogleDriveAPI::uploadFile] Failed to initiate upload for \"$name\"", $postData, true);

        $this->validateApiResponse($response, "[GoogleDriveAPI::uploadFile] Failed to initiate upload for \"$name\"", 200, 'null');

        preg_match('/location: (.*)/i', $response['headers'], $matches);
        $uploadUrl = $matches[1] ?? null;
        if (!$uploadUrl) {
            throw new RuntimeException('[GoogleDriveAPI::uploadFile] Upload URL not found in response headers. Response: ' . $response['body']);
        }

        $headers = [
            'Content-Type: application/octet-stream',
            "Authorization: Bearer $this->accessToken",
        ];

        $response = $this->makeCurlRequest(trim($uploadUrl), 'PUT', $headers, "[GoogleDriveAPI::uploadFile] Failed to upload data for \"$name\"", $fileData);

        $jsonResult = $this->validateApiResponse($response, "[GoogleDriveAPI::uploadFile] Failed to upload data for \"$name\"", 200);

        if (empty($jsonResult['id'])) {
            throw new RuntimeException('[GoogleDriveAPI::uploadFile] File ID not found in response. Response: ' . $response['body']);
        }

        return $jsonResult['id'];
    }
}