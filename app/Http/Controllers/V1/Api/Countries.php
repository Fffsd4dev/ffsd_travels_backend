<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class Countries extends Controller
{
    protected $cacheKey = 'countries_data';
    protected $cacheDuration = 60 * 24*720; // Cache for 3 years

    public function index()
    {
        // Clear the cache before loading the data
        Cache::forget($this->cacheKey);

        $countries = Cache::remember($this->cacheKey, $this->cacheDuration, function () {
            return $this->loadCountriesFromFile();
        });

        return response()->json($countries);
    }

    protected function loadCountriesFromFile()
    {
        // Adjust path if necessary
        $path = storage_path('app/countries.json');

        if (!File::exists($path)) {
            throw new \Exception('Countries JSON file not found');
        }

        $json = File::get($path);
        return json_decode($json, true); // Use true to return an associative array
    }
}
