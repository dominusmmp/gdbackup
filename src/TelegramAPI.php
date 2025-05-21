<?php

declare(strict_types=1);
defined('GDBPATH') || die('forbidden');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Logger.php';

/**
 * Handles interactions with Telegram Bot API for file uploads and messages.
 */
class TelegramAPI
{
    private const API_URL = 'https://api.telegram.org/bot';
    private const RATE_LIMIT_INTERVAL = 0.1;

    private string $botToken;
    private string $chatId;
    private int $fileSizeLimit;

    /**
     * Initializes the Telegram API client.
     *
     * @param string $botToken Telegram Bot API token.
     * @param string $chatId Telegram chat ID for backups.
     * @param int $fileSizeLimit Maximum file size in bytes (default: 50MB, 2GB for premium bots).
     * @throws InvalidArgumentException If required arguments are missing or invalid.
     */
    public function __construct(string $botToken, string $chatId, int $fileSizeLimit = 50000000)
    {
        $missingArgs = array_filter([
            empty($botToken) ? 'botToken' : null,
            empty($chatId) ? 'chatId' : null,
        ]);

        if (!empty($missingArgs)) {
            throw new InvalidArgumentException(
                sprintf('[TelegramAPI] Missing required arguments: %s', implode(', ', $missingArgs))
            );
        }

        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $botToken)) {
            throw new InvalidArgumentException('[TelegramAPI] Invalid Telegram Bot Token format.');
        }

        if (!preg_match('/^(@[A-Za-z0-9_]+|-\d+|\d+)$/', $chatId)) {
            throw new InvalidArgumentException('[TelegramAPI] Invalid Telegram Chat ID format.');
        }

        if ($fileSizeLimit < 1 || $fileSizeLimit > 2000000000) {
            throw new InvalidArgumentException('[TelegramAPI] File size limit must be between 1 byte and 2GB.');
        }

        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->fileSizeLimit = $fileSizeLimit;
    }

    /**
     * Enforces rate limiting to avoid Telegram API quotas.
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
     * Performs a cURL request to the Telegram API.
     *
     * @param string $method API method (e.g., 'sendDocument').
     * @param array $params Request parameters.
     * @return array Decoded JSON response.
     * @throws RuntimeException If the request fails.
     */
    private function makeRequest(string $method, array $params): array
    {
        $this->rateLimit();

        $params['chat_id'] = $this->chatId;

        $url = self::API_URL . $this->botToken . '/' . $method;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            throw new RuntimeException("[TelegramAPI::makeRequest] Telegram API request failed: $error");
        }

        $jsonResult = json_decode($result, true);
        if ($status !== 200 || empty($jsonResult['ok'])) {
            throw new RuntimeException(sprintf(
                '[TelegramAPI::makeRequest] Telegram API error (HTTP %d): %s',
                $status,
                $jsonResult['description'] ?? $result
            ));
        }

        return $jsonResult['result'];
    }

    /**
     * Uploads a file to a Telegram chat.
     *
     * @param string $name File name.
     * @param string $path Local file path.
     * @return string Message ID of the uploaded file.
     * @throws InvalidArgumentException If arguments are invalid.
     * @throws RuntimeException If upload fails.
     */
    public function uploadFile(string $name, string $path): string
    {
        if (empty($name)) {
            throw new InvalidArgumentException('[TelegramAPI::uploadFile] File name is required.');
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException("[TelegramAPI::uploadFile] File does not exist: $path");
        }

        if (!is_readable($realPath)) {
            throw new RuntimeException("[TelegramAPI::uploadFile] File is not readable: $path");
        }

        $fileSize = filesize($realPath);
        if ($fileSize === false || $fileSize > $this->fileSizeLimit) {
            throw new RuntimeException(sprintf(
                '[TelegramAPI::uploadFile] File size exceeds Telegram limit (%dMB): %s',
                $this->fileSizeLimit / 1000000,
                $path
            ));
        }

        $params['document'] = new CURLFile($realPath, 'application/zip', $name);

        $result = $this->makeRequest('sendDocument', $params);

        if (empty($result['message_id'])) {
            throw new RuntimeException('[TelegramAPI::uploadFile] Message ID not found in response. Response: ' . json_encode($result));
        }

        return (string) $result['message_id'];
    }

    /**
     * Sends a text message to the Telegram chat.
     *
     * @param string $text Message text.
     * @throws RuntimeException If the request fails.
     */
    public function sendMessage(string $text): void
    {
        $params['text'] = $text;
        $this->makeRequest('sendMessage', $params);
    }
}