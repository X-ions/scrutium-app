<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeviceFingerprint
{
    public function generate(Request $request): string
    {
        $components = $this->extractComponents($request);
        $fingerprint = $this->hashComponents($components);
        
        return $fingerprint;
    }

    public function extractComponents(Request $request): array
    {
        $userAgent = $request->header('User-Agent', '');
        $acceptLanguage = $request->header('Accept-Language', '');
        $acceptEncoding = $request->header('Accept-Encoding', '');
        $accept = $request->header('Accept', '');
        $connection = $request->header('Connection', '');
        $dnt = $request->header('DNT', '');
        $secChUa = $request->header('Sec-CH-UA', '');
        $secChUaMobile = $request->header('Sec-CH-UA-Mobile', '');
        $secChUaPlatform = $request->header('Sec-CH-UA-Platform', '');
        $secChUaFullVersionList = $request->header('Sec-CH-UA-Full-Version-List', '');

        return [
            'user_agent' => $userAgent,
            'accept_language' => $acceptLanguage,
            'accept_encoding' => $acceptEncoding,
            'accept' => $accept,
            'connection' => $connection,
            'dnt' => $dnt,
            'sec_ch_ua' => $secChUa,
            'sec_ch_ua_mobile' => $secChUaMobile,
            'sec_ch_ua_platform' => $secChUaPlatform,
            'sec_ch_ua_full_version_list' => $secChUaFullVersionList,
            'screen_width' => $request->input('screen_width'),
            'screen_height' => $request->input('screen_height'),
            'color_depth' => $request->input('color_depth'),
            'timezone_offset' => $request->input('timezone_offset'),
            'timezone' => $request->input('timezone'),
            'canvas_fingerprint' => $request->input('canvas_fingerprint'),
            'webgl_vendor' => $request->input('webgl_vendor'),
            'webgl_renderer' => $request->input('webgl_renderer'),
            'audio_fingerprint' => $request->input('audio_fingerprint'),
            'fonts' => $request->input('fonts'),
            'plugins' => $request->input('plugins'),
        ];
    }

    public function hashComponents(array $components): string
    {
        $stableComponents = [];
        
        foreach ($components as $key => $value) {
            if ($value !== null && $value !== '') {
                $stableComponents[$key] = $value;
            }
        }
        
        ksort($stableComponents);
        $json = json_encode($stableComponents, JSON_SORT_KEYS);
        
        return hash('sha256', $json);
    }

    public function parseUserAgent(string $userAgent): array
    {
        $cacheKey = 'ua_parser_' . hash('sha256', $userAgent);
        
        return Cache::remember($cacheKey, 86400 * 30, function () use ($userAgent) {
            return $this->doParseUserAgent($userAgent);
        });
    }

    protected function doParseUserAgent(string $userAgent): array
    {
        $browser = 'Unknown';
        $browserVersion = 'Unknown';
        $os = 'Unknown';
        $osVersion = 'Unknown';
        $deviceType = 'desktop';
        $deviceBrand = null;
        $deviceModel = null;

        // Browser detection
        if (preg_match('/Edg\/(\d+\.\d+\.\d+\.\d+)/', $userAgent, $matches)) {
            $browser = 'Edge';
            $browserVersion = $matches[1];
        } elseif (preg_match('/OPR\/(\d+\.\d+\.\d+\.\d+)/', $userAgent, $matches)) {
            $browser = 'Opera';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Chrome\/(\d+\.\d+\.\d+\.\d+)/', $userAgent, $matches) && !preg_match('/Edg|OPR/', $userAgent)) {
            $browser = 'Chrome';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Firefox\/(\d+\.\d+)/', $userAgent, $matches)) {
            $browser = 'Firefox';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Safari\/(\d+\.\d+)/', $userAgent, $matches) && !preg_match('/Chrome|Edg|OPR/', $userAgent)) {
            $browser = 'Safari';
            $browserVersion = $matches[1];
        } elseif (preg_match('/MSIE (\d+\.\d+)|Trident\/.*rv:(\d+\.\d+)/', $userAgent, $matches)) {
            $browser = 'Internet Explorer';
            $browserVersion = $matches[1] ?? $matches[2];
        }

        // OS detection
        if (preg_match('/Windows NT (\d+\.\d+)/', $userAgent, $matches)) {
            $os = 'Windows';
            $versions = [
                '10.0' => '10/11',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
                '6.0' => 'Vista',
                '5.1' => 'XP',
            ];
            $osVersion = $versions[$matches[1]] ?? $matches[1];
        } elseif (preg_match('/Mac OS X (\d+[._]\d+[._]\d+)/', $userAgent, $matches)) {
            $os = 'macOS';
            $osVersion = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Linux/', $userAgent)) {
            $os = 'Linux';
            if (preg_match('/Android (\d+\.\d+)/', $userAgent, $matches)) {
                $os = 'Android';
                $osVersion = $matches[1];
                $deviceType = 'mobile';
            }
        } elseif (preg_match('/iPhone OS (\d+[._]\d+[._]\d+)/', $userAgent, $matches)) {
            $os = 'iOS';
            $osVersion = str_replace('_', '.', $matches[1]);
            $deviceType = 'mobile';
            $deviceBrand = 'Apple';
            $deviceModel = 'iPhone';
        } elseif (preg_match('/iPad.*OS (\d+[._]\d+[._]\d+)/', $userAgent, $matches)) {
            $os = 'iPadOS';
            $osVersion = str_replace('_', '.', $matches[1]);
            $deviceType = 'tablet';
            $deviceBrand = 'Apple';
            $deviceModel = 'iPad';
        }

        // Mobile device detection
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod/', $userAgent)) {
            $deviceType = 'mobile';
        } elseif (preg_match('/Tablet|iPad|Android(?!.*Mobile)/', $userAgent)) {
            $deviceType = 'tablet';
        }

        // Extract device model for common devices
        if ($deviceType !== 'desktop') {
            if (preg_match('/iPhone(\d+,\d+)/', $userAgent, $matches)) {
                $deviceModel = 'iPhone ' . $this->mapIPhoneModel($matches[1]);
            } elseif (preg_match('/iPad(\d+,\d+)/', $userAgent, $matches)) {
                $deviceModel = 'iPad ' . $this->mapIPadModel($matches[1]);
            } elseif (preg_match('/(SM-[A-Z]\d+)/', $userAgent, $matches)) {
                $deviceBrand = 'Samsung';
                $deviceModel = $matches[1];
            } elseif (preg_match('/(Pixel \d+)/', $userAgent, $matches)) {
                $deviceBrand = 'Google';
                $deviceModel = $matches[1];
            }
        }

        return [
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'os' => $os,
            'os_version' => $osVersion,
            'device_type' => $deviceType,
            'device_brand' => $deviceBrand,
            'device_model' => $deviceModel,
        ];
    }

    protected function mapIPhoneModel(string $model): string
    {
        $models = [
            '17,1' => '15 Pro Max', '17,2' => '15 Pro', '17,3' => '15', '17,4' => '15 Plus',
            '16,1' => '14 Pro Max', '16,2' => '14 Pro', '16,3' => '14', '16,4' => '14 Plus',
            '15,1' => '13 Pro Max', '15,2' => '13 Pro', '15,3' => '13', '15,4' => '13 mini',
            '14,1' => '12 Pro Max', '14,2' => '12 Pro', '14,3' => '12', '14,4' => '12 mini',
        ];
        return $models[$model] ?? $model;
    }

    protected function mapIPadModel(string $model): string
    {
        $models = [
            '13,8' => 'Pro 12.9" (6th)', '13,9' => 'Pro 11" (4th)',
            '13,10' => 'Air (5th)', '13,11' => 'Air (5th)',
        ];
        return $models[$model] ?? $model;
    }

    public function generateFingerprintId(): string
    {
        return Str::lower(Str::random(16));
    }

    public function calculateSimilarity(string $fp1, string $fp2): float
    {
        if ($fp1 === $fp2) {
            return 1.0;
        }
        
        $bits1 = str_split(base_convert($fp1, 16, 2));
        $bits2 = str_split(base_convert($fp2, 16, 2));
        
        $matches = 0;
        $total = min(count($bits1), count($bits2));
        
        for ($i = 0; $i < $total; $i++) {
            if ($bits1[$i] === $bits2[$i]) {
                $matches++;
            }
        }
        
        return $total > 0 ? $matches / $total : 0.0;
    }
}