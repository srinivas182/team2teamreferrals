<?php

declare(strict_types=1);

namespace App\Domains\Community;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** "Something new in this network's feed": only the post id travels; the page fetches what the viewer may see. */
final class FeedChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $group, public readonly int $post) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('groups.'.$this->group)];
    }

    public function broadcastAs(): string
    {
        return 'feed.changed';
    }

    /** @return array{post: int} */
    public function broadcastWith(): array
    {
        return ['post' => $this->post];
    }
}
