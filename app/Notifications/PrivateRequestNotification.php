<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PrivateSessionRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the private session request emails (prompt 15B): every one is
 * about one request, which is also its subject in the notification log.
 */
abstract class PrivateRequestNotification extends CustomerNotification
{
    public function __construct(public readonly PrivateSessionRequest $request)
    {
        parent::__construct();
    }

    public function subject(): Model
    {
        return $this->request;
    }
}
