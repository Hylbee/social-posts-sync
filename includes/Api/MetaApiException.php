<?php
/**
 * Meta API Exception
 *
 * Thrown when the Meta Graph API returns an error response.
 *
 * @package SocialPostsSync\Api
 */

declare(strict_types=1);

namespace SocialPostsSync\Api;

defined('ABSPATH') || exit;

/**
 * Exception thrown when the Meta API returns an error response.
 */
class MetaApiException extends \RuntimeException {

    private int    $api_code;
    private string $api_type;
    private string $api_fbtrace_id;

    public function __construct(
        string $message,
        int $api_code = 0,
        string $api_type = '',
        string $api_fbtrace_id = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $api_code, $previous);
        $this->api_code       = $api_code;
        $this->api_type       = $api_type;
        $this->api_fbtrace_id = $api_fbtrace_id;
    }

    public function getApiCode(): int      { return $this->api_code; }
    public function getApiType(): string   { return $this->api_type; }
    public function getFbTraceId(): string { return $this->api_fbtrace_id; }
}
