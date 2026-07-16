<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * Backing store for session data. The default implementation uses PHP's
 * native session (which may itself be backed by Redis in production via
 * php.ini). An array store is provided for tests. See Req 2.2 / 17.1.
 */
interface SessionStore
{
    /** Begin/resume the session and return current data. @return array<string,mixed> */
    public function load(): array;

    /** Persist the working data set. @param array<string,mixed> $data */
    public function save(array $data): void;

    /** Current session id. */
    public function id(): string;

    /** Regenerate the session id (fixation defense) and return the new id. */
    public function regenerateId(): string;

    /** Destroy the session entirely. */
    public function destroy(): void;
}
