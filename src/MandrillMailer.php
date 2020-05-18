<?php declare(strict_types = 1);

namespace Fabian\Mandrill;

/**
 * Provides functionality to compose and send email via Mandrill service.
 *
 * @author Lukas Vana
 */
class MandrillMailer implements \Nette\Mail\IMailer
{

	/**
	 * Mandrill API key
	 * @var string
	 */
	private $apiKey;

	/**
	 * Mandrill API endpoint
	 * @var string
	 */
	private $apiEndpoint = "https://mandrillapp.com/api/1.0";

	/**
	 * Input and output format
	 * Currently supported only json;)
	 * @var string
	 */
	private $apiFormat = 'json';


	public function __construct($apiKey)
	{
		$this->apiKey = $apiKey;
	}


	/**
	 * Sends email via Mandrill.
	 */
	public function send(\Nette\Mail\Message $message): void
	{
		if ($message instanceof Message) {
			$params = $message->getMandrillParams();
		} else {
			$params = $this->parseNetteMessage($message);
		}
		$attachments = $this->parseAttachments($message);
		if (\count($attachments) !== 0) {
			$params['attachments'] = $attachments;
		}

		$this->callApi($params);
	}


	/**
	 * Parse Nette Message headers to Mandrill API params
	 */
	private function parseNetteMessage(\Nette\Mail\Message $message): array
	{
		$params = [];

		$params['subject'] = $message->getSubject();
		$params['text'] = $message->getBody();
		$params['html'] = $message->getHtmlBody();
		$from = $message->getFrom();
		if (empty($from)) {
			throw new MandrillException('Please specify From parameter!');
		}
		$params['from_email'] = \key($from);
		$params['from_name'] = $from[$params['from_email']];
		$params['to'] = \array_merge(
			$this->parseNetteRecipients($message, 'to'),
			$this->parseNetteRecipients($message, 'cc'),
			$this->parseNetteRecipients($message, 'bcc')
		);

		$params['headers'] = [];
		foreach ($message->getHeaders() as $name => $value) {
			switch ($name) {
				case 'Date':
				case 'Subject':
				case 'MIME-Version':
				case 'Cc':
				case 'Bcc':
				case 'To':
					break;
				case 'Tags':
					$params['tags'] = \explode(',', $value);
					break;
				default:
					$params['headers'][$name] = $value;
					break;
			}
		}

		return $params;
	}


	/**
	 * Parse Nette Message headers for To, Cc or Bcc recipients.
	 */
	private function parseNetteRecipients(\Nette\Mail\Message $message, string $type): array
	{
		$netteRecipients = $message->getHeader(\ucfirst($type));
		$recipients = [];
		if (\is_array($netteRecipients)) {
			foreach ($netteRecipients as $email => $name) {
				$recipient = ['email' => $email];
				if (!empty($name)) {
					$recipient['name'] = $name;
				}
				$recipient['type'] = $type;
				$recipients[] = $recipient;
			}
		}
		return $recipients;
	}


	/**
	 * Call Mandrill API and send email
	 * @throws MandrillException
	 */
	private function callApi(array $params): array
	{
		$params = [
			'key' => $this->apiKey,
			'message' => $params,
			'async' => true,
		];
		$params = \json_encode($params);

		$method = '/messages/send';

		$ch = \curl_init();
		\curl_setopt($ch, CURLOPT_USERAGENT, 'Mandrill-Nette-PHP/0.2');
		\curl_setopt($ch, CURLOPT_POST, true);
		\curl_setopt($ch, CURLOPT_HEADER, false);
		\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		\curl_setopt($ch, CURLOPT_TIMEOUT, 600);
		\curl_setopt($ch, CURLOPT_URL, $this->apiEndpoint . $method . '.' . $this->apiFormat);
		\curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/' . $this->apiFormat,
		]);
		\curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		if (\curl_error($ch)) {
			throw new MandrillException(
				'curl error while calling ' . $method . ': ' . \curl_error($ch)
			);
		}

		$response = (string) \curl_exec($ch);
		$info = \curl_getinfo($ch);
		\curl_close($ch);

		$status = $info['http_code'] ?? null;
		$result = \json_decode($response, true);

		if ($status === 200) {
			if ($result === null) {
				throw new MandrillException('Unable to parse JSON response');
			}
			return $result;
		}

		$message = $result['message'] ?? 'Unable to parse JSON response';
		throw new MandrillException('Error ' . $status . ' Message: ' . $message);
	}


	private function parseAttachments(\Nette\Mail\Message $message)
	{
		$attachments = [];

		foreach ($message->getAttachments() as $attachment) {
			$attachments[] = [
				'type' => $attachment->getHeader('Content-Type'),
				'name' => $this->extractFilename($attachment->getHeader('Content-Disposition')),
				'content' => $this->encodeMessage($attachment),
			];
		}

		return $attachments;
	}


	private function extractFilename($header)
	{
		\preg_match('/filename="([a-zA-Z0-9. -_]{1,})"/', $header, $matches);
		return $matches[1];
	}


	private function encodeMessage($attachment)
	{
		$lines = \explode("\n", $attachment->getEncodedMessage());

		$output = '';

		for ($i = 4; $i < \count($lines); $i++) {
			$output .= $lines[$i];
		}

		return $output;
	}
}
