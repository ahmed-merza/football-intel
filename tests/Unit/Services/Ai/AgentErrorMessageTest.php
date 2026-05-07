<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AgentErrorMessage;
use RuntimeException;
use Tests\TestCase;

class AgentErrorMessageTest extends TestCase
{
    public function test_translates_anthropic_401_to_a_credentials_message(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'HTTP request returned status code 401: {"type":"error","error":{"type":"authentication_error","message":"x-api-key header is required"}}',
        ));

        $this->assertStringContainsString('credentials missing or expired', $msg);
        $this->assertStringNotContainsString('x-api-key header', $msg);
        $this->assertStringNotContainsString('401', $msg);
    }

    public function test_translates_504_to_a_proxy_timeout_message(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'HTTP request returned status code 504: <html><head><title>504 Gateway Time-out</title></head>',
        ));

        $this->assertStringContainsString('AI proxy', $msg);
        $this->assertStringNotContainsString('<html>', $msg);
    }

    public function test_translates_curl_28_timeout_to_a_friendly_timeout_message(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'cURL error 28: Operation timed out after 300002 milliseconds with 0 bytes received',
        ));

        $this->assertStringContainsString('timed out', $msg);
        $this->assertStringNotContainsString('cURL', $msg);
    }

    public function test_translates_invalid_json_to_a_parse_message(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'n8n response was not valid JSON: I am sorry I cannot answer that.',
        ));

        $this->assertStringContainsString('could not parse', $msg);
    }

    public function test_falls_back_to_a_generic_message_for_unknown_exceptions(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'Some weird internal error nobody has seen before',
        ));

        $this->assertStringContainsString('failed unexpectedly', $msg);
        $this->assertStringContainsString('logged', $msg);
    }

    public function test_redacts_anthropic_api_keys_that_slip_through(): void
    {
        // Worst case: pattern not matched, raw message reaches redaction.
        // Faux Anthropic-style key.
        $key = 'sk-ant-api03-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            "Some weird error and the leaked key was {$key} so be careful",
        ));

        $this->assertStringNotContainsString($key, $msg);
    }

    public function test_redacts_authorization_header_fragments(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'Failed: Authorization: Bearer abcdefghijklmnopqrstuvwxyz123456 was rejected',
        ));

        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz123456', $msg);
    }

    public function test_caps_message_length_at_200_chars(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            str_repeat('Some weird internal error nobody has seen before. ', 100),
        ));

        $this->assertLessThanOrEqual(200, mb_strlen($msg));
    }

    public function test_keeps_already_friendly_eligibility_messages(): void
    {
        $msg = AgentErrorMessage::humanise(new RuntimeException(
            'Need at least one blood test AND one body-composition record on file.',
        ));

        $this->assertStringContainsString('blood test', $msg);
        $this->assertStringContainsString('body-composition', $msg);
    }
}
