<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Gateways\GoogleCalendarSdkClient;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Exercises GoogleCalendarSdkClient below the GoogleCalendarClient seam —
 * the one place that ever touches the google/apiclient SDK. These tests
 * reach private methods via reflection deliberately: everything here is
 * local (Client/event/config object construction, exception
 * translation) and never performs real network I/O, so no HTTP fake is
 * needed. Auth attempts using the deliberately-invalid fake private key
 * fail inside openssl locally (verified manually — a `DomainException`
 * in ~30ms), never reaching the network.
 */
class GoogleCalendarSdkClientTest extends TestCase
{
    private const FAKE_CREDENTIALS = '{"type":"service_account","client_id":"116902683368346528512","client_email":"siri-education@siri-education.iam.gserviceaccount.com","private_key":"FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP"}';

    /** @param  array<string, mixed>  $payload */
    private function buildEvent(array $payload): Event
    {
        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'buildEvent');

        return $method->invoke(new GoogleCalendarSdkClient, $payload);
    }

    /** @return array<string, mixed> */
    private function samplePayload(): array
    {
        return [
            'summary' => 'Lesson',
            'description' => 'Booking reference: BK-1',
            'start' => ['dateTime' => '2026-08-01T10:00:00+00:00', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-08-01T10:30:00+00:00', 'timeZone' => 'UTC'],
        ];
    }

    public function test_conference_solution_key_type_is_hangouts_meet(): void
    {
        $event = $this->buildEvent($this->samplePayload());

        $type = $event->getConferenceData()->getCreateRequest()->getConferenceSolutionKey()->getType();

        $this->assertSame('hangoutsMeet', $type);
        $this->assertNotSame(GoogleCalendarMeetProvider::KEY, $type);
        $this->assertNotSame('google_meet', $type);
        $this->assertNotSame('googleMeet', $type);
        $this->assertNotSame('Google Meet', $type);
        $this->assertNotSame('meet', $type);
    }

    public function test_conference_request_id_is_present_and_unique_when_not_supplied(): void
    {
        $eventA = $this->buildEvent($this->samplePayload());
        $eventB = $this->buildEvent($this->samplePayload());

        $idA = $eventA->getConferenceData()->getCreateRequest()->getRequestId();
        $idB = $eventB->getConferenceData()->getCreateRequest()->getRequestId();

        $this->assertNotEmpty($idA);
        $this->assertNotEmpty($idB);
        $this->assertNotSame($idA, $idB);
    }

    public function test_conference_request_id_uses_supplied_value_when_given(): void
    {
        $payload = $this->samplePayload();
        $payload['conferenceRequestId'] = 'explicit-request-id';

        $event = $this->buildEvent($payload);

        $this->assertSame('explicit-request-id', $event->getConferenceData()->getCreateRequest()->getRequestId());
    }

    public function test_insert_event_default_conference_data_version_is_integer_one(): void
    {
        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'insertEvent');
        $parameters = collect($method->getParameters())->keyBy(fn ($p) => $p->getName());

        $conferenceDataVersion = $parameters->get('conferenceDataVersion');

        $this->assertSame('int', (string) $conferenceDataVersion->getType());
        $this->assertSame(1, $conferenceDataVersion->getDefaultValue());
        $this->assertIsInt($conferenceDataVersion->getDefaultValue());
    }

    public function test_delegated_subject_parameter_is_required_not_optional(): void
    {
        // Domain-wide delegation must never be silently skipped — every
        // interface method requires the delegated subject as a plain,
        // non-nullable, non-default parameter.
        foreach (['insertEvent', 'updateEvent', 'deleteEvent', 'getEvent', 'allowedConferenceTypes', 'verifyTokenAcquisition'] as $methodName) {
            $method = new ReflectionMethod(GoogleCalendarSdkClient::class, $methodName);
            $parameters = collect($method->getParameters())->keyBy(fn ($p) => $p->getName());
            $subject = $parameters->get('delegatedSubject');

            $this->assertNotNull($subject, "{$methodName} must declare \$delegatedSubject");
            $this->assertFalse($subject->isOptional(), "{$methodName}'s \$delegatedSubject must not be optional");
            $this->assertFalse($subject->allowsNull(), "{$methodName}'s \$delegatedSubject must not allow null");
        }
    }

    public function test_requested_scopes_is_exactly_the_calendar_scope(): void
    {
        $scopes = (new GoogleCalendarSdkClient)->requestedScopes();

        $this->assertSame([Calendar::CALENDAR], $scopes);
        $this->assertSame(['https://www.googleapis.com/auth/calendar'], $scopes);
    }

    public function test_build_client_requests_only_the_calendar_scope(): void
    {
        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'buildClient');
        $decoded = json_decode(self::FAKE_CREDENTIALS, true);

        $client = $method->invoke(new GoogleCalendarSdkClient, $decoded, 'meetings@example.com');

        $this->assertSame(['https://www.googleapis.com/auth/calendar'], $client->getScopes());
    }

    public function test_build_client_sets_delegated_subject_unconditionally(): void
    {
        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'buildClient');
        $decoded = json_decode(self::FAKE_CREDENTIALS, true);

        $client = $method->invoke(new GoogleCalendarSdkClient, $decoded, 'meetings@example.com');

        $config = (new ReflectionProperty($client, 'config'))->getValue($client);

        $this->assertSame('meetings@example.com', $config['subject']);
    }

    public function test_service_construction_fails_at_the_token_step_before_any_calendar_api_call(): void
    {
        // Proves setSubject() was applied (the client_id/email/subject
        // show up in the resulting diagnostic) and that no Calendar
        // service/API call is ever reached when token acquisition fails
        // — the exact separation item 4 requires.
        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'service');

        try {
            $method->invoke(new GoogleCalendarSdkClient, self::FAKE_CREDENTIALS, 'meetings@example.com');
            $this->fail('Expected a GatewayRequestException from token acquisition.');
        } catch (GatewayRequestException $e) {
            $this->assertStringContainsString('116902683368346528512', $e->getMessage());
            $this->assertStringContainsString('siri-education@siri-education.iam.gserviceaccount.com', $e->getMessage());
            $this->assertStringContainsString('meetings@example.com', $e->getMessage());
            $this->assertStringContainsString('https://www.googleapis.com/auth/calendar', $e->getMessage());
            $this->assertStringNotContainsString('FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP', $e->getMessage());
        }
    }

    public function test_translates_googles_invalid_conference_type_error_into_safe_rich_exception(): void
    {
        $googleException = new GoogleServiceException(
            'Invalid conference type value.',
            400,
            null,
            [['domain' => 'global', 'reason' => 'invalid', 'message' => 'Invalid conference type value.']],
        );

        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'translateApiException');
        $result = $method->invoke(
            new GoogleCalendarSdkClient,
            $googleException,
            'calendar-123@group.calendar.google.com',
            'meetings@example.com',
            self::FAKE_CREDENTIALS,
        );

        $this->assertInstanceOf(GatewayRequestException::class, $result);
        $this->assertStringContainsString('400', $result->getMessage());
        $this->assertStringContainsString('invalid', $result->getMessage());
        $this->assertStringContainsString('Invalid conference type value.', $result->getMessage());
        $this->assertStringContainsString('calendar-123@group.calendar.google.com', $result->getMessage());
        $this->assertStringContainsString('meetings@example.com', $result->getMessage());
        $this->assertStringContainsString('hangoutsMeet', $result->getMessage());

        // Never exposes anything token/key-shaped from the fake credentials.
        $this->assertStringNotContainsString('FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP', $result->getMessage());
        $this->assertStringNotContainsString('siri-education@siri-education.iam.gserviceaccount.com', $result->getMessage());
    }

    public function test_translate_api_exception_never_throws_when_enrichment_lookup_fails(): void
    {
        $googleException = new GoogleServiceException(
            'Invalid conference type value.',
            400,
            null,
            [['domain' => 'global', 'reason' => 'invalid', 'message' => 'Invalid conference type value.']],
        );

        $method = new ReflectionMethod(GoogleCalendarSdkClient::class, 'translateApiException');

        // The best-effort "allowed conference types" enrichment call
        // inevitably fails against the fake credentials — this must
        // never surface as an unhandled exception, only as a plainer
        // (but still safe) message.
        $result = $method->invoke(
            new GoogleCalendarSdkClient,
            $googleException,
            'primary',
            'meetings@example.com',
            self::FAKE_CREDENTIALS,
        );

        $this->assertInstanceOf(GatewayRequestException::class, $result);
    }

    public function test_verify_token_acquisition_throws_safe_exception_never_wrapping_a_google_service_exception_message_directly(): void
    {
        $client = new GoogleCalendarSdkClient;

        try {
            $client->verifyTokenAcquisition(self::FAKE_CREDENTIALS, 'meetings@example.com');
            $this->fail('Expected a GatewayRequestException.');
        } catch (GatewayRequestException $e) {
            $this->assertStringNotContainsString('FAKE_PRIVATE_KEY_TOKEN_ABCDEFGHIJKLMNOP', $e->getMessage());
        }
    }
}
