<?php

use App\Domains\Identity\Devices\DeviceRecorder;
use App\Domains\Identity\Phone\PhoneNumber;

it('normalises US mobile numbers to E.164', function (string $in, ?string $out) {
    expect(PhoneNumber::normalize($in))->toBe($out);
})->with([
    ['(435) 555-0119', '+14355550119'],
    ['+1 801 555 0142', '+18015550142'],
    ['18015550142', '+18015550142'],
    ['123', null],
    ['(135) 555-0119', null],
]);

it('masks and formats numbers for display', function () {
    expect(PhoneNumber::masked('+14355550119'))->toBe('(•••) •••-0119')
        ->and(PhoneNumber::display('+14355550119'))->toBe('(435) 555-0119');
});

it('labels devices from the user agent', function (string $ua, string $label) {
    expect(DeviceRecorder::label($ua))->toBe($label);
})->with([
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari on macOS'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge on Windows'],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36', 'Chrome on Android'],
]);
