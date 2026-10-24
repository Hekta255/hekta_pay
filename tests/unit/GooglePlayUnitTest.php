<?php

declare(strict_types=1);

use HektaPay\GooglePlay\HttpTransport;
use HektaPay\GooglePlay\PlayApiClient;
use HektaPay\GooglePlay\PlayApiException;
use HektaPay\GooglePlay\PlayPurchaseVerifier;
use HektaPay\GooglePlay\PubSubJwtVerifier;
use HektaPay\GooglePlay\ServiceAccountAuth;
use HektaPay\GooglePlay\TokenVault;

/** Scripted transport: each queued response is returned once, in order. */
final class ScriptedTransport implements HttpTransport
{
    /** @var array<int,array{status:int, body:string}|Throwable> */
    public array $queue = [];
    /** @var array<int,array<string,mixed>> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 20): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next ?? ['status' => 500, 'body' => '{}'];
    }
}

function purchased_payload(array $override = []): array
{
    return array_replace_recursive([
        'productLineItem' => [['productId' => 'nafdex_shop_monthly', 'productOfferDetails' => ['quantity' => 1, 'consumptionState' => 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED']]],
        'purchaseStateContext' => ['purchaseState' => 'PURCHASED'],
        'orderId' => 'GPA.1111-2222-3333-44444',
        'obfuscatedExternalAccountId' => 'invoice-1',
        'regionCode' => 'UG',
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
    ], $override);
}

$expected = ['product_id' => 'nafdex_shop_monthly', 'invoice_id' => 'invoice-1', 'allow_test' => false];

T::test('verifier accepts a purchased product bound to the invoice', function () use ($expected): void {
    $result = PlayPurchaseVerifier::evaluate(purchased_payload(), $expected);
    T::same(PlayPurchaseVerifier::OK, $result['result']);
    T::same('GPA.1111-2222-3333-44444', $result['order_id']);
    T::same('UG', $result['region_code']);
    T::false($result['is_test']);
});

T::test('verifier accepts the profile id as the invoice binding', function () use ($expected): void {
    $payload = purchased_payload(['obfuscatedExternalAccountId' => '']);
    $payload['obfuscatedExternalProfileId'] = 'invoice-1';
    T::same(PlayPurchaseVerifier::OK, PlayPurchaseVerifier::evaluate($payload, $expected)['result']);
});

T::test('verifier reports pending purchases without rejecting them', function () use ($expected): void {
    $result = PlayPurchaseVerifier::evaluate(purchased_payload(['purchaseStateContext' => ['purchaseState' => 'PENDING']]), $expected);
    T::same(PlayPurchaseVerifier::PENDING, $result['result']);
});

T::test('verifier rejects cancelled, wrong product, wrong invoice, quantity, and missing order', function () use ($expected): void {
    $cases = [
        'PURCHASE_NOT_COMPLETED' => purchased_payload(['purchaseStateContext' => ['purchaseState' => 'CANCELLED']]),
        'PRODUCT_MISMATCH' => purchased_payload(['productLineItem' => [['productId' => 'nafdex_shop_annual']]]),
        'INVOICE_BINDING_MISMATCH' => purchased_payload(['obfuscatedExternalAccountId' => 'someone-else']),
        'QUANTITY_INVALID' => purchased_payload(['productLineItem' => [['productOfferDetails' => ['quantity' => 2]]]]),
        'ORDER_MISSING' => array_diff_key(purchased_payload(), ['orderId' => 1]),
    ];
    foreach ($cases as $code => $payload) {
        $result = PlayPurchaseVerifier::evaluate($payload, $expected);
        T::same(PlayPurchaseVerifier::REJECTED, $result['result'], $code);
        T::same($code, $result['code']);
    }
    $twoItems = purchased_payload();
    $twoItems['productLineItem'][] = ['productId' => 'nafdex_shop_monthly'];
    T::same('PRODUCT_MISMATCH', PlayPurchaseVerifier::evaluate($twoItems, $expected)['code']);
});

T::test('verifier rejects test purchases unless explicitly allowed', function () use ($expected): void {
    $payload = purchased_payload();
    $payload['testPurchaseContext'] = ['fopType' => 'TEST'];
    $rejected = PlayPurchaseVerifier::evaluate($payload, $expected);
    T::same('TEST_PURCHASE_REJECTED', $rejected['code']);
    T::true($rejected['is_test']);
    T::same(PlayPurchaseVerifier::OK, PlayPurchaseVerifier::evaluate($payload, ['allow_test' => true] + $expected)['result']);
});

T::test('verifier surfaces consumption and acknowledgement state', function () use ($expected): void {
    $payload = purchased_payload(['acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED']);
    $payload['productLineItem'][0]['productOfferDetails']['consumptionState'] = 'CONSUMPTION_STATE_CONSUMED';
    $result = PlayPurchaseVerifier::evaluate($payload, $expected);
    T::true($result['acknowledged']);
    T::true($result['consumed']);
});

T::test('token vault round-trips, hashes deterministically, and rejects tampering', function (): void {
    $vault = new TokenVault('a-long-random-secret-value');
    $encrypted = $vault->encrypt('token-123');
    T::true($encrypted !== 'token-123');
    T::same('token-123', $vault->decrypt($encrypted));
    T::true($vault->encrypt('token-123') !== $encrypted, 'IV must differ per encryption');
    T::same(64, strlen(TokenVault::hash('token-123')));
    T::same(TokenVault::hash('x'), TokenVault::hash('x'));
    $raw = base64_decode($encrypted, true);
    $tampered = base64_encode(substr($raw, 0, -1) . chr(ord(substr($raw, -1)) ^ 1));
    T::throws(RuntimeException::class, fn() => $vault->decrypt($tampered));
    T::throws(RuntimeException::class, fn() => (new TokenVault('another-long-secret-value'))->decrypt($encrypted));
    T::throws(RuntimeException::class, fn() => new TokenVault('short'));
});

T::test('service account auth signs a valid RS256 assertion and caches the access token', function (): void {
    $keys = test_rsa_key();
    $transport = new ScriptedTransport();
    $transport->queue[] = ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])];
    $now = 1_800_000_000;
    $auth = new ServiceAccountAuth(['client_email' => 'svc@project.iam.gserviceaccount.com', 'private_key' => $keys['private']], $transport, fn() => $now);

    [$header, $claims, $signature] = explode('.', $auth->buildAssertion());
    $decode = static fn(string $value): string => base64_decode(strtr($value, '-_', '+/'));
    T::same('RS256', json_decode($decode($header), true)['alg']);
    $payload = json_decode($decode($claims), true);
    T::same('svc@project.iam.gserviceaccount.com', $payload['iss']);
    T::same(ServiceAccountAuth::SCOPE, $payload['scope']);
    T::same('https://oauth2.googleapis.com/token', $payload['aud']);
    T::same($now + 3600, $payload['exp']);
    T::same(1, openssl_verify($header . '.' . $claims, $decode($signature), $keys['public'], OPENSSL_ALGO_SHA256));

    T::same('ya29.test', $auth->accessToken());
    T::same('ya29.test', $auth->accessToken());
    T::same(1, count($transport->requests), 'token must be cached');
    T::true(strpos((string) $transport->requests[0]['body'], 'grant_type=') === 0);
});

T::test('service account auth rejects invalid credentials and failed exchanges', function (): void {
    $transport = new ScriptedTransport();
    T::throws(RuntimeException::class, fn() => new ServiceAccountAuth(['client_email' => 'x'], $transport));
    $keys = test_rsa_key();
    $auth = new ServiceAccountAuth(['client_email' => 'svc@x', 'private_key' => $keys['private']], $transport);
    $transport->queue[] = ['status' => 400, 'body' => '{"error":"invalid_grant"}'];
    T::throws(PlayApiException::class, fn() => $auth->accessToken());
});

function play_client(ScriptedTransport $transport, array &$sleeps = []): PlayApiClient
{
    $keys = test_rsa_key();
    $auth = new ServiceAccountAuth(['client_email' => 'svc@x', 'private_key' => $keys['private']], $transport);
    return new PlayApiClient($auth, $transport, function (int $ms) use (&$sleeps): void {
        $sleeps[] = $ms;
    });
}

$tokenResponse = ['status' => 200, 'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600])];

T::test('play api client reads a purchase and encodes the token in the URL', function () use ($tokenResponse): void {
    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 200, 'body' => json_encode(purchased_payload())]];
    $result = play_client($transport)->getProductPurchase('com.sebuleni.nafdex', 'a/b+c');
    T::same('GPA.1111-2222-3333-44444', $result['orderId']);
    $request = $transport->requests[1];
    T::same('GET', $request['method']);
    T::same('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.sebuleni.nafdex/purchases/productsv2/tokens/a%2Fb%2Bc', $request['url']);
    T::true(in_array('Authorization: Bearer tok', $request['headers'], true));
});

T::test('play api client consumes with POST and an empty body', function () use ($tokenResponse): void {
    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 200, 'body' => '']];
    play_client($transport)->consume('com.sebuleni.nafdex', 'nafdex_shop_monthly', 'tok1');
    T::same('POST', $transport->requests[1]['method']);
    T::same('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.sebuleni.nafdex/purchases/products/nafdex_shop_monthly/tokens/tok1:consume', $transport->requests[1]['url']);
});

T::test('play api client retries transient failures with backoff and gives up after three attempts', function () use ($tokenResponse): void {
    $sleeps = [];
    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 503, 'body' => '{}'], ['status' => 429, 'body' => '{}'], ['status' => 200, 'body' => '{"ok":true}']];
    T::same(['ok' => true], play_client($transport, $sleeps)->getProductPurchase('p', 't'));
    T::same([250, 500], $sleeps);

    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 500, 'body' => '{}'], ['status' => 500, 'body' => '{}'], ['status' => 500, 'body' => '{}']];
    $error = T::throws(PlayApiException::class, fn() => play_client($transport)->getProductPurchase('p', 't'));
    T::same(500, $error->httpStatus());
});

T::test('play api client does not retry client errors and flags not-found', function () use ($tokenResponse): void {
    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 404, 'body' => '{"error":{"message":"not found"}}']];
    $error = T::throws(PlayApiException::class, fn() => play_client($transport)->getProductPurchase('p', 't'));
    T::true($error->isNotFound());
    T::same(2, count($transport->requests));
});

T::test('play api client lists voided purchases with pagination parameters', function () use ($tokenResponse): void {
    $transport = new ScriptedTransport();
    $transport->queue = [$tokenResponse, ['status' => 200, 'body' => '{"voidedPurchases":[]}']];
    play_client($transport)->listVoidedPurchases('com.sebuleni.nafdex', 1000, 2000, 'next');
    $url = $transport->requests[1]['url'];
    T::true(strpos($url, 'purchases/voidedpurchases?') !== false);
    T::true(strpos($url, 'startTime=1000') !== false && strpos($url, 'endTime=2000') !== false && strpos($url, 'token=next') !== false && strpos($url, 'type=0') !== false);
});

function make_pubsub_jwt(array $keys, array $claims, string $kid = 'kid-1', string $alg = 'RS256'): string
{
    $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $head = $encode(json_encode(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT']));
    $body = $encode(json_encode($claims));
    $signature = '';
    openssl_sign($head . '.' . $body, $signature, $keys['resource'], OPENSSL_ALGO_SHA256);
    return $head . '.' . $body . '.' . $encode($signature);
}

T::test('pub/sub verifier accepts a correctly signed token for the expected audience and account', function (): void {
    $keys = test_rsa_key();
    $now = 1_800_000_000;
    $verifier = new PubSubJwtVerifier('https://pay.sebuleni.com/api/google-play/rtdn', 'push@proj.iam.gserviceaccount.com', fn() => ['kid-1' => $keys['public']], fn() => $now);
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => 'https://pay.sebuleni.com/api/google-play/rtdn', 'email' => 'push@proj.iam.gserviceaccount.com', 'email_verified' => true, 'iat' => $now - 10, 'exp' => $now + 3000];
    T::same('push@proj.iam.gserviceaccount.com', $verifier->verify('Bearer ' . make_pubsub_jwt($keys, $claims))['email']);

    $bad = [
        'wrong audience' => ['aud' => 'https://evil.example'] + $claims,
        'wrong email' => ['email' => 'other@proj.iam.gserviceaccount.com'] + $claims,
        'unverified email' => ['email_verified' => false] + $claims,
        'wrong issuer' => ['iss' => 'https://evil.example'] + $claims,
        'expired' => ['exp' => $now - 600] + $claims,
    ];
    foreach ($bad as $label => $badClaims) {
        T::throws(RuntimeException::class, fn() => $verifier->verify('Bearer ' . make_pubsub_jwt($keys, $badClaims)), null);
    }
});

T::test('pub/sub verifier rejects forged signatures, unknown keys, bad algorithms and missing headers', function (): void {
    $keys = test_rsa_key();
    $other = test_rsa_key();
    $now = 1_800_000_000;
    $claims = ['iss' => 'accounts.google.com', 'aud' => 'aud', 'email' => 'push@x', 'email_verified' => true, 'exp' => $now + 3000];
    $verifier = new PubSubJwtVerifier('aud', 'push@x', fn() => ['kid-1' => $keys['public']], fn() => $now);

    T::throws(RuntimeException::class, fn() => $verifier->verify('Bearer ' . make_pubsub_jwt($other, $claims)));
    T::throws(RuntimeException::class, fn() => $verifier->verify('Bearer ' . make_pubsub_jwt($keys, $claims, 'unknown-kid')));
    T::throws(RuntimeException::class, fn() => $verifier->verify('Bearer ' . make_pubsub_jwt($keys, $claims, 'kid-1', 'none')));
    T::throws(RuntimeException::class, fn() => $verifier->verify(''));
    T::throws(RuntimeException::class, fn() => $verifier->verify('Bearer not.a.jwt'));
    T::same('push@x', $verifier->verify('bearer ' . make_pubsub_jwt($keys, $claims))['email']);
});
