<?php

namespace BeyondCode\LaravelWebSockets\WebSockets\Messages;

use BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;
use stdClass;

class PusherChannelProtocolMessage implements PusherMessage
{
    /** @var \stdClass */
    protected $payload;

    /** @var \React\Socket\ConnectionInterface */
    protected $connection;

    /** @var \BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManager */
    protected $channelManager;

    public function __construct(stdClass $payload, ConnectionInterface $connection, ChannelManager $channelManager)
    {
        $this->payload = $payload;

        $this->connection = $connection;

        $this->channelManager = $channelManager;
    }

    public function respond()
    {
        $eventName = Str::camel(Str::after($this->payload->event, ':'));

        if (method_exists($this, $eventName) && $eventName !== 'respond') {
            call_user_func([$this, $eventName], $this->connection, $this->payload->data ?? new stdClass());
        }
    }

    /*
     * @link https://pusher.com/docs/pusher_protocol#ping-pong
     */
    protected function ping(ConnectionInterface $connection)
    {
        $connection->send(json_encode([
            'event' => 'pusher:pong',
        ]));
    }

    /*
     * аутентификация и получение токена
     */
    protected function login(ConnectionInterface $connection, stdClass $payload)
    {
        $remember = null;
        $credentials = ['email' => $payload->email, 'password' => $payload->password];

        if (!Auth::attempt($credentials)) {
            $data = [
                'result' => 'error',
                'status' => 401,
                'message' => 'You cannot sign with those credentials',
                'errors' => 'Unauthorised',
            ];
        } else {
            $token = Auth::user()->createToken(config('app.name'));
            $token->token->expires_at = $remember ?
                Carbon::now()->addMonth() :
                Carbon::now()->addDay();

            $token->token->save();

            $data = [
                'result' => 'ok',
                'status' => 200,
                'token_type' => 'Bearer',
                'token' => $token->accessToken,
                'expires_at' => Carbon::parse($token->token->expires_at)->toDateTimeString()
            ];
        }

        $connection->send(json_encode([
            'data' => $data,
        ]));
    }

    /*
     * @link https://pusher.com/docs/pusher_protocol#pusher-subscribe
     */
    protected function subscribe(ConnectionInterface $connection, stdClass $payload)
    {
        $channel = $this->channelManager->findOrCreate($connection->app->id, $payload->channel);

        $channel->subscribe($connection, $payload);
    }

    public function unsubscribe(ConnectionInterface $connection, stdClass $payload)
    {
        $channel = $this->channelManager->findOrCreate($connection->app->id, $payload->channel);

        $channel->unsubscribe($connection);
    }
}
