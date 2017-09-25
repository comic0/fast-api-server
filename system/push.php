<?php

use sngrl\PhpFirebaseCloudMessaging\Client;
use sngrl\PhpFirebaseCloudMessaging\Message;
use sngrl\PhpFirebaseCloudMessaging\Recipient\Device;
use sngrl\PhpFirebaseCloudMessaging\Notification;

class Push
{
    private $server_key;
    private $client;

    public function __construct( $key )
    {
        $this->server_key = $key;

        $this->client = new Client();
        $this->client->setApiKey($this->server_key);
        $this->client->injectGuzzleHttpClient(new \GuzzleHttp\Client());
    }

    public function sendMessage( $tokens, $title, $body, $data=null )
    {
        if( !is_array($tokens) )
        {
            $tokens = array($tokens);
        }

        if( count($tokens)>0 )
        {
            $message = new Message();
            $message->setPriority('high');

            foreach( $tokens as $token )
            {
                $message->addRecipient(new Device($token));
            }

            $message->setNotification(new Notification($title, $body));

            if( !is_null($data) )
            {
                $message->setData($data);
            }

            $response = $this->client->send($message);

            return $response->getStatusCode();
        }
    }
}