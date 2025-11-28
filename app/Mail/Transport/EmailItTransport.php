<?php

namespace App\Mail\Transport;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class EmailItTransport extends AbstractTransport
{
    protected string $apiKey;
    protected string $apiHost;
    protected string $apiProto;
    protected string $apiPath;

    public function __construct(string $apiKey, string $apiHost, string $apiProto, string $apiPath)
    {
        parent::__construct();
        
        $this->apiKey = $apiKey;
        $this->apiHost = $apiHost;
        $this->apiProto = $apiProto;
        $this->apiPath = $apiPath;
    }

    protected function doSend(SymfonySentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        
        $from = $email->getFrom()[0];
        $to = $email->getTo();
        $subject = $email->getSubject();
        $body = $email->getHtmlBody() ?? $email->getTextBody();

        // EmailIt expects a simple format
        $toEmail = $to[0]->getAddress(); // Get first recipient
        
        $data = [
            'from' => $from->getName() ? $from->getName() . ' <' . $from->getAddress() . '>' : $from->getAddress(),
            'to' => $toEmail,
            'subject' => $subject,
            'html' => $body
        ];

        $url = "{$this->apiProto}://{$this->apiHost}/{$this->apiPath}/emails";
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json'
        ])->post($url, $data);

        if (!$response->successful()) {
            throw new \Exception('EmailIt API request failed: ' . $response->body());
        }
    }

    public function __toString(): string
    {
        return 'emailit';
    }
}