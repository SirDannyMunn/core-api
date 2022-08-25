<?php

namespace Fleetbase\Support;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Doctrine\Inflector\InflectorFactory;
use Errorname\VINDecoder\Decoder as VinDecoder;
use PragmaRX\Countries\Package\Countries;
use PragmaRX\Countries\Package\Services\Config;
use PragmaRX\Countries\Package\Support\Collection as CountryCollection;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Model;
use Fleetbase\Models\File;
use Fleetbase\Models\Place;
use Fleetbase\Models\Company;
use Grimzy\LaravelMysqlSpatial\Eloquent\SpatialExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vinkla\Hashids\Facades\Hashids;
use Stringy\Stringy;
use NumberFormatter;
use DateTimeZone;
use ErrorException;
use ReflectionClass;
use SqlFormatter;
use ReflectionException;
use SplStack;

class Utils
{
    /**
     * Generates a URL to this API
     * 
     * @param string $path
     * @param null|array $queryParams
     * @param string $subdomain
     * @return string
     */
    public static function apiUrl(string $path, ?array $queryParams = null, $subdomain = 'api'): string
    {
        if (app()->environment(['local', 'development'])) {
            $subdomain = 'v2api';
        }

        return static::consoleUrl($path, $queryParams, $subdomain);
    }

    /**
     * Generate a url to the console
     *
     * @param string $path
     * @param null|array $queryParams
     * @param string $subdomain
     * @return string
     */
    public static function consoleUrl(string $path, ?array $queryParams = null, $subdomain = 'console'): string
    {
        $url = 'https://' . $subdomain;

        if (app()->environment(['qa', 'staging'])) {
            $url .= '.' . app()->environment();
        }

        if (app()->environment(['local', 'development'])) {
            $url .= '.fleetbase.engineering';
        } else {
            $url .= '.fleetbase.io';
        }

        if (!empty($path)) {
            $url = Str::startsWith($path, '/') ? $url . $path : $url . '/' . $path;
        }

        if ($queryParams) {
            $url = $url . '?' . http_build_query($queryParams);
        }

        return $url;
    }

    /**
     * Return asset URL from s3.
     *
     * @param string $string
     * @param string $pattern
     * @return boolean
     */
    public static function fromS3(string $path, $bucket = null, $region = null): string
    {
        $bucket = $bucket ?? config('filesystems.disks.s3.bucket');
        $region = $region ?? config('filesystems.disks.s3.region');

        return 'https://' . $bucket . '.s3-' . $region . '.amazonaws.com/' . $path;
        // return 'https://s3.' . $region . '.amazonaws.com/' . $bucket . '/' . $path;
    }

    /**
     * Return asset URL from s3.
     *
     * @param string $string
     * @param string $pattern
     * @return boolean
     */
    public static function assetFromS3(string $path): string
    {
        return static::fromS3($path, 'flb-assets');
    }

    /**
     * Checks if string contains a match for given regex pattern.
     *
     * @param string $string
     * @param string $pattern
     * @return boolean
     */
    public static function stringMatches(string $string, $pattern): bool
    {
        $matches = [];
        preg_match($pattern, $string, $matches);

        return (bool) count($matches);
    }

    /**
     * Extracts the matched pattern from the string.
     *
     * @param string $string
     * @param string $pattern
     * @return string|null
     */
    public static function stringExtract(string $string, $pattern): ?string
    {
        $matches = [];
        preg_match($pattern, $string, $matches);

        return Arr::first($matches);
    }

    /**
     * Converts headers array to key value using the colon : delimieter.
     *
     * ```
     * $headers = ['Content-Type: application/json]
     *
     * keyHeaders($headers) // ['Content-Type' => 'application/json']
     * ```
     *
     * @param array $headers
     * @return array
     */
    public static function keyHeaders(array $headers): array
    {
        $keyHeaders = [];

        foreach ($headers as $header) {
            [$key, $value] = explode(':', $header);

            $keyHeaders[$key] = $value;
        }

        return $keyHeaders;
    }

    /**
     * Converts headers array to key value using the colon : delimieter.
     *
     * ```
     * $headers = ['Content-Type' => 'application/json']
     *
     * unkeyHeaders($headers) // ['Content-Type: application/json']
     * ```
     *
     * @param array $headers
     * @return array
     */
    public static function unkeyHeaders(array $headers): array
    {
        $unkeyedHeaders = [];

        foreach ($headers as $key => $header) {
            if (is_numeric($key)) {
                $unkeyedHeaders[] = $header;
                continue;
            }

            $unkeyedHeaders[] = $key . ': ' . $header;
        }

        return $unkeyedHeaders;
    }

    /**
     * Converts a place to an address string.
     *
     * @param string $binaryString
     * @return array
     */
    public static function getAddressStringForPlace($place, $useHtml = false, $except = [])
    {
        $address = $useHtml ? '<address>' : '';
        $parts = collect(['name', 'street1', 'street2', 'city', 'province', 'postal_code', 'country_name'])->filter(function ($part) use ($except) {
            return is_array($except) ? !in_array($part, $except) : true;
        })->values();
        $numberOfParts = $parts->count();
        $addressValues = [];
        $seperator = $useHtml ? '<br>' : ' - ';

        for ($i = 0; $i < $numberOfParts; $i++) {
            $key = $parts[$i];
            $value = strtoupper(static::get($place, $key)) ?? null;

            // if value empty skip or value equal to last value skip
            if (Utils::isEmpty($value) || in_array($value, $addressValues) || (Str::contains(static::get($place, 'street1'), $value) && $key !== 'street1')) {
                continue;
            }

            $addressValues[$key] = $value;
        }

        foreach ($addressValues as $key => $value) {
            if ($key === array_key_last($addressValues)) {
                $seperator = '';
            }

            if ($useHtml && in_array($key, ['street1', 'street2', 'postal_code'])) {
                $seperator = '<br>';
            }

            $address .= strtoupper($value) . $seperator;
            $seperator = ', ';
        }

        if ($useHtml) {
            $address .= '</address>';
        }

        return $address;
    }

    /**
     * Unpacks a mysql POINT column from binary to array
     *
     * @param string $binaryString
     * @return array
     */
    public static function unpackPoint($bindaryString)
    {
        return unpack('x/x/x/x/corder/Ltype/dlat/dlon', $bindaryString);
    }

    /**
     * Unpacks a mysql POINT column from binary to array
     *
     * @param string $rawPoint
     * @return \Grimzy\LaravelMysqlSpatial\Types\Point
     */
    public static function mysqlPointAsGeometry($rawPoint)
    {
        $coordinates = static::unpackPoint($rawPoint);

        return new Point($coordinates['lon'], $coordinates['lat']);
    }

    /**
     * Creates an object from an array.
     *
     * @param array $attributes
     * @return stdObject
     */
    public static function createObject($attributes = [])
    {
        return (object) $attributes;
    }

    /**
     * Converts a time/date string to a mysql datetime.
     *
     * @param string $string
     * @return string
     */
    public static function toMySqlDatetime($string)
    {
        $string = preg_replace('/\([a-z0-9 ]+\)/i', '', $string);
        return date('Y-m-d H:i:s', strtotime($string));
    }

    /**
     * Converts a time/date string to a mysql datetime.
     *
     * @param string $string
     * @return string
     */
    public static function toDatetime($string)
    {
        return Carbon::parse($string)->toDateTime();
    }

    /**
     * Check if the value is a valid date
     *
     * @param mixed $value
     * @return boolean
     */
    public static function isDate($value)
    {
        if (!$value) {
            return false;
        }

        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a given string is a valid UUID
     * 
     * @param   string  $uuid   The string to check
     * @return  boolean
     */
    public static function isUuid(?string $uuid): bool
    {
        if (!is_string($uuid) || (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1)) {
            return false;
        }

        return true;
    }

    /**
     * Converts a QueryBuilder to a string
     *
     * @param QueryBuilder $query
     * @return string
     */
    public static function queryBuilderToString($query)
    {
        return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
    }

    /**
     * Dump and die's a formatted SQL string
     *
     * @param string $string
     * @return string
     */
    public static function sqlDump($sql, $die = true, $withoutBinding = false)
    {
        if (is_object($sql) && $withoutBinding === false) {
            $sql = static::queryBuilderToString($sql);
        } elseif (is_object($sql)) {
            $sql = $sql->toSql();
        }

        $sql = SqlFormatter::format($sql);
        if ($die) {
            exit($sql);
        } else {
            print($sql);
        }
    }

    /**
     * Replaces any parameter placeholders in a query with the value of that
     * parameter. Useful for debugging. Assumes anonymous parameters from 
     * $params are are in the same order as specified in $query
     *
     * @param string $query The sql query with parameter placeholders
     * @param array $params The array of substitution parameters
     * @return string The interpolated query
     */
    public static function interpolateQuery($query, $params)
    {
        $keys = array();

        # build a regular expression for each parameter
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $keys[] = '/:' . $key . '/';
            } else {
                $keys[] = '/[?]/';
            }
        }

        $query = preg_replace($keys, $params, $query, 1, $count);

        #trigger_error('replaced '.$count.' keys');

        return $query;
    }

    /**
     * Determines if variable is not empty
     *
     * @param mixed $var
     * @return boolean
     */
    public static function isset($var, $key = null)
    {
        if ($key !== null && is_string($key)) {
            return null !== Utils::get($var, $key);
        }

        return isset($var);
    }

    /**
     * Determines if variable is not empty
     *
     * @param mixed $var
     * @return boolean
     */
    public static function notEmpty($var)
    {
        return !empty($var);
    }

    /**
     * Determines if variable is empty
     *
     * @param mixed $var
     * @return boolean
     */
    public static function isEmpty($var)
    {
        return empty($var);
    }

    /**
     * Casts value to boolean.
     *
     * @param mixed $val
     * @param boolean $return_null
     * @return boolean
     */
    public static function castBoolean($val): bool
    {
        if (is_null($val)) {
            return false;
        }

        if (is_string($val) && in_array($val, ['true', '1', 'truthy', 'on'])) {
            return true;
        }

        if (is_string($val) && in_array($val, ['false', '0', '-1', 'falsey', 'off'])) {
            return false;
        }

        if (is_string($val)) {
            return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return (bool) $val;
    }

    public static function isBooleanValue($val)
    {
        if (is_bool($val)) {
            return true;
        }

        if (is_string($val)) {
            return in_array($val, ['true', 'false', '1', '0']);
        }

        return false;
    }

    /**
     * Checks if a value is true.
     *
     * @param mixed $val
     * @param boolean $return_null
     * @return boolean
     */
    public static function isTrue($val, $return_null = false)
    {
        $boolval = static::castBoolean($val);

        return $boolval === null && !$return_null ? false : $boolval;
    }

    /**
     * Checks if a value is false.
     *
     * @param mixed $val
     * @param boolean $return_null
     * @return boolean
     */
    public static function isFalse($val, $return_null = false)
    {
        return !static::isTrue($val, $return_null);
    }

    /**
     * Checks if a value is valid json.
     *
     * @param string $string
     * @return boolean
     */
    public static function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return json_last_error() == JSON_ERROR_NONE;
    }

    /**
     * Parse a SQL error exception to a string.
     *
     * @param  string $error
     * @return string
     */
    public static function sqlExceptionString($error)
    {
        if (is_object($error)) {
            $error = $error->getMessage();
        }
        if (Str::contains($error, ']:') && Str::contains($error, '(')) {
            $error = explode(']:', $error);
            $error = explode('(', $error[1]);

            return trim($error[0]);
        }

        return $error;
    }

    /**
     * Returns the short version class name for an object
     * without its namespace.
     *
     * @param object|string $class
     * @return string
     */
    public static function classBasename($class): ?string
    {
        if (function_exists('class_basename')) {
            return class_basename($class);
        }

        $className = null;

        try {
            $className = (new ReflectionClass($class))->getShortName();
        } catch (ReflectionException $e) {
            //
        }

        return $className;
    }

    /**
     * Pluralizes a string
     *
     * @param string $text
     * @return string
     */
    public static function pluralize(?string $text): string
    {
        if (!is_string($text)) {
            return '';
        }

        $inflector = InflectorFactory::create()->build();

        return $inflector->pluralize($text);
    }

    /**
     * Singularizes a string
     *
     * @param string $text
     * @return string
     */
    public static function singularize(?string $text): string
    {
        if (!is_string($text)) {
            return '';
        }

        $inflector = InflectorFactory::create()->build();

        return $inflector->singularize($text);
    }

    /**
     * Tableize a string
     *
     * @param string $text
     * @return string
     */
    public static function tableize($text): string
    {
        $inflector = InflectorFactory::create()->build();

        return $inflector->tableize($text);
    }

    /**
     * Alias for strtolower
     *
     * @param string $str
     * @return string
     */
    public static function lowercase($str)
    {
        return Str::lower($str);
    }

    /**
     * Returns an instance of use Stringy\Stringy;
     *
     * @param string $str
     * @return \Stringy\Stringy
     */
    public static function string(string $str): Stringy
    {
        return Stringy::create($str);
    }

    /**
     * Humanize a string
     *
     * @param string $str
     * @return string
     */
    public static function humanize(?string $str): string
    {
        return static::string($str)->toLowerCase()->humanize();
    }

    /**
     * "Smart" humanize a string by retaining common abbreviation cases
     *
     * @param string $str
     * @return string
     */
    public static function smartHumanize(?string $str): string
    {
        $search = ['api', 'vat', 'id', 'sku'];
        $replace = array_map(function ($word) {
            return strtoupper($word);
        }, $search);

        $subject = static::string($str)->toLowerCase()->humanize();

        return Str::replace($search, $replace, $subject);
    }

    /**
     * Returns the short version class name for an object
     * without its namespace.
     *
     * @param string|array $table
     * @param array $where
     *
     * @return string
     */
    public static function getUuid($table, $where = [])
    {
        if (is_array($table)) {
            foreach ($table as $t) {
                $uuid = static::getUuid($t, $where);

                if ($uuid) {
                    return ['uuid' => $uuid, 'table' => static::pluralize($t)];
                }
            }
            return;
        }

        $result =  DB::table(static::pluralize($table))
            ->select(['uuid'])
            ->where($where)->first();

        // static::sqlDump($result, false);

        // $result = $result->first();
        // dump($result);

        return $result->uuid ?? null;
    }

    /**
     * Returns the model for the specific where clause, and can check accross multiple tables
     *
     * @param string|array $table
     * @param array $where
     *
     * @return \Fleetbase\Models\Model
     */
    public static function findModel($table, $where = [])
    {
        if (is_array($table)) {
            foreach ($table as $t) {
                $model =
                    DB::table($t)
                    ->select(['*'])
                    ->where($where)
                    ->first() ?? null;
                if ($model) {
                    return $model;
                }
            }
        }
        return DB::table($table)
            ->select(['*'])
            ->where($where)
            ->first() ?? null;
    }

    /**
     * Generate a random number with specified length
     *
     * @param int length
     * @return int
     */
    public static function randomNumber($length = 4)
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= mt_rand(0, 9);
        }

        return $result;
    }

    /**
     * Converts the param to an integer with numbers only
     *
     * @param string|mixed $string
     * @return int
     */
    public static function numbersOnly($string)
    {
        return intval(preg_replace('/[^0-9]/', '', $string));
    }

    /**
     * Removes all special charavters from a string, unless excepted characters are supplied
     *
     * @param string|mixed $string
     * @param array $except
     * @return string
     */
    public static function removeSpecialCharacters($string, $except = [])
    {
        $regex = '/[^a-zA-Z0-9';

        if (is_array($except)) {
            foreach ($except as $char) {
                $regex .= $char;
            }
        }

        $regex .= ']/';

        return preg_replace($regex, '', $string);
    }

    /**
     * Format number to a particular currency.
     *
     * @param float $amount amount to format
     * @param string $currency the currency to format into
     * @param boolean $cents whether if amount is in cents, this will auto divide by 100
     * @return string
     */
    public static function moneyFormat($amount, $currency = 'USD', $cents = true)
    {
        $amount = $cents === true ? static::numbersOnly($amount) / 100 : $amount;
        $money = new Money($amount, $currency);

        return $money->format();
    }

    /**
     * Calculates the percentage of a integer
     *
     * @param integer|float $percentage
     * @param integer $number
     *
     * @return integer
     */
    public static function calculatePercentage($percentage, $number)
    {
        return ($percentage / 100) * $number;
    }

    /**
     * Retrieves a model class name given a string
     *
     * @param int length
     * @return int
     */
    public static function getModelClassName($table, $namespace = '\\Fleetbase\\Models\\')
    {
        if (is_object($table)) {
            $table = static::classBasename($table);
        }

        if (Str::startsWith($table, $namespace)) {
            return $table;
        }

        $modelName = Str::studly(static::singularize($table));

        return $namespace . $modelName;
    }

    /**
     * Converts a model name or table name into a mutation type for eloquent relationships.
     * 
     * store:storefront -> Fleetbase\Models\Storefront\Store
     * order -> Fleetbase\Models\Order
     * Fleetbase\Models\Order -> Fleetbase\Models\Order
     * 
     * @param string|object type
     * @return string
     */
    public static function getMutationType($type): string
    {
        if (is_object($type)) {
            return get_class($type);
        }

        if (Str::contains($type, '\\')) {
            return $type;
        }

        if (Str::contains($type, ':')) {
            $namespace = explode(':', $type);
            $type = $namespace[0];
            $namespace = 'Fleetbase\\Models\\' . Str::studly($namespace[1]) . '\\';

            return Utils::getModelClassName($type, $namespace);
        }

        return Utils::getModelClassName($type);
    }

    /**
     * Retrieves a model class name ans turns it to a type
     * 
     * ex: UserDevice -> user-device
     *
     * @param int length
     * @return int
     */
    public static function getTypeFromClassName($className)
    {
        $basename = static::classBasename($className);
        $basename = static::classBasename($basename);

        return Str::slug($basename);
    }

    /**
     * Retrieves a model class name ans turns it to a type
     * 
     * ex: UserDevice -> user device
     *
     * @param int length
     * @return int
     */
    public static function humanizeClassName($className)
    {
        $basename = static::classBasename($className);

        return (string) static::humanize(Str::snake($basename));
    }

    /**
     * Retrieve the first value available from the targets
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function firstValue($target, $keys = [], $default = null)
    {
        if (!is_object($target) && !is_array($target)) {
            return $default;
        }

        foreach ($keys as $key) {
            $value = static::get($target, $key);

            if ($value) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Alias for data_get
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function get($target, $key, $default = null)
    {
        return data_get($target, $key, $default);
    }

    /**
     * Returns first available property value from a target array or object.
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function or($target, $keys = [], $defaultValue = null)
    {
        foreach ($keys as $key) {
            if (static::isset($target, $key)) {
                return static::get($target, $key);
            }
        }

        return $defaultValue;
    }

    /**
     * Alias for data_set
     *
     * @param mixed target
     * @param string key
     * @param boolean overwrite
     *
     * @return mixed
     */
    public static function set($target, $key, $value, $overwrite = true)
    {
        return data_set($target, $key, $value, $overwrite);
    }

    /**
     * Alias for data_set
     *
     * @param mixed target
     * @param string key
     * @param boolean overwrite
     *
     * @return mixed
     */
    public static function setProperties($target, $properties, $overwrite = true)
    {
        foreach ($properties as $key => $value) {
            $target = static::set($target, $key, $value, $overwrite);
        }

        return $target;
    }

    /**
     * Check if key exists
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function exists($target, $key)
    {
        return static::notEmpty(static::get($target, $key));
    }

    /**
     * Check if key is not on target
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function notSet($target, $key)
    {
        return static::isEmpty(static::get($target, $key));
    }

    /**
     * Validate string if is valid fleetbase public_id
     *
     * @param string $string
     *
     * @return boolean
     */
    public static function isPublicId($string)
    {
        return is_string($string) && Str::contains($string, ['_']) && strlen(explode('_', $string)[1]) === 7;
    }

    /**
     * Checks if target is iterable and gets the count
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function count($target, $key)
    {
        $subject = static::get($target, $key);

        if (!is_iterable($subject)) {
            return 0;
        }

        return count($subject);
    }

    /**
     * Check if target is not scalar
     *
     * @param mixed target
     * @param string key
     * @param mixed default
     *
     * @return mixed
     */
    public static function isNotScalar($target)
    {
        return !is_scalar($target);
    }

    /**
     * Returns the ISO2 country name by providing a countries full name
     *
     * @param string countryName
     *
     * @return string
     */
    public static function getCountryCodeByName($countryName)
    {
        $countries = new Countries();
        $countries = $countries
            ->all()
            ->map(function ($country) {
                return [
                    'name' => static::get($country, 'name.common'),
                    'iso2' => static::get($country, 'cca2'),
                ];
            })
            ->values()
            ->toArray();
        $countries = collect($countries);

        $data = $countries->first(function ($country) use ($countryName) {
            // @todo switch to string contains or like search
            return strtolower($country['name']) === strtolower($countryName);
        });

        // if faield try to find by the first word of the countryName
        if (!$data) {
            $cnSplit = explode(' ', $countryName);
            if (count($cnSplit) > 1 && strlen($cnSplit[0])) {
                return static::getCountryCodeByName($cnSplit[0]);
            }
        }

        return static::get($data, 'iso2') ?? null;
    }

    /**
     * Returns the ISO2 country name by providing a countries full name
     *
     * @param string $timezone
     * @return \PragmaRX\Countries\Package\Support\Collection
     */
    public static function findCountryFromTimezone(string $timezone): CountryCollection
    {
        $countries = new Countries(new Config([
            'hydrate' => [
                'elements' => [
                    'timezones' => true,
                ],
            ],
        ]));

        return $countries->filter(function ($country) use ($timezone) {
            return $country->timezones->filter(function ($tzData) use ($timezone) {
                return $tzData->zone_name === $timezone;
            })->count();
        });
    }

    /**
     * Returns additional country data from iso2 format
     *
     * @param string country
     *
     * @return array
     */
    public static function getCountryData($country)
    {
        if (static::isEmpty($country)) {
            return null;
        }

        $storageKey = 'countryData:' . $country;

        if (Redis::exists($storageKey)) {
            return json_decode(Redis::get($storageKey));
        }

        $data = (new Countries())
            ->where('cca2', $country)
            ->map(function ($country) {
                $longitude = (float) static::get($country, 'geo.longitude_desc') ?? 0;
                $latitutde = (float) static::get($country, 'geo.latitude_desc') ?? 0;

                return [
                    'iso3' => static::get($country, 'cca3'),
                    'iso2' => static::get($country, 'cca2'),
                    'emoji' => Utils::get($country, 'flag.emoji'),
                    'name' => Utils::get($country, 'name'),
                    'aliases' => Utils::get($country, 'alt_spellings', []),
                    'capital' => Utils::get($country, 'capital_rinvex'),
                    'geo' => Utils::get($country, 'geo'),
                    'coordinates' => ['longitude' => $longitude, 'latitude' => $latitutde],
                ];
            })
            ->first()
            ->toArray();

        if ($data) {
            Redis::set($storageKey, json_encode($data));
        }

        return $data ?? null;
    }

    /**
     * Finds and identifies resource relations and maps them to their respective
     * service, resource model, console link, and id
     *
     * @param object $obj
     * @return string
     */
    public static function mapResourceRelations($ids = [])
    {
        // map of relation meta
        $map = [
            'driver_' => [
                'service' => 'fleet-ops',
                'model' => 'driver',
                'link' => 'management.drivers.index.details',
            ],
            'place_' => [
                'service' => 'fleet-ops',
                'model' => 'place',
                'link' => 'management.places.index.details',
            ],
            'order_' => [
                'service' => 'fleet-ops',
                'model' => 'order',
                'link' => 'operations.orders.index.details',
            ],
        ];

        // mapped meta relation info
        $relations = [];

        // build mappings
        foreach ($ids as $id) {
            if (!Str::contains($id, '_')) {
                continue;
            }

            $idPrefix = explode('_', $id)[0];

            if (isset($map[$idPrefix])) {
                $relations[$id] = [...$map[$idPrefix], 'id' => $id];
            }
        }

        return $relations;
    }

    /**
     * Decodes vehicle identification number into array
     *
     * @param string $vin
     * @return array
     */
    public static function decodeVin($vin)
    {
        $vin = VinDecoder::decode($vin);
        return $vin;
    }

    /**
     * Looks up a user client info w/ api
     *
     * @param string $ip
     * @return stdClass
     */
    public static function lookupIp($ip = null)
    {
        if ($ip === null) {
            $ip = request()->ip();
        }

        $curl = new \Curl\Curl();
        $curl->get('https://api.ipdata.co/' . $ip, ['api-key' => env('IPINFO_API_KEY', 'c7350212ccc98d1a1663c89ff9f063c381b0aed49141c6faec968688')]);

        return $curl->response;
    }

    /**
     * Checks if value is valid latitude coordinate.
     *
     * @param int $num
     * @return boolean
     */
    public static function isLatitude($num): bool
    {
        if (!is_numeric($num) || is_null($num)) {
            return false;
        }

        // cast to float
        $num = (float) $num;

        return is_finite($num) && $num >= -90 && $num <= 90;
    }

    /**
     * Checks if value is valid longitude coordinate.
     *
     * @param int $num
     * @return boolean
     */
    public static function isLongitude($num): bool
    {
        if (!is_numeric($num) || is_null($num)) {
            return false;
        }

        // cast to float
        $num = (float) $num;

        return is_finite($num) && $num >= -180 && $num <= 180;
    }

    public static function cleanCoordinateString($string)
    {
        return preg_replace('/[^0-9.]/', '', $string);
    }

    /**
     * Checks if value is valid longitude coordinate.
     *
     * @todo check for geojson and point instances
     * @param int $num
     * @return boolean
     */
    public static function isCoordinates($coordinates): bool
    {
        $latitude = null;
        $longitude = null;

        if ($coordinates instanceof SpatialExpression) {
            $coordinates = $coordinates->getSpatialValue();
        }

        if ($coordinates instanceof Place) {
            $coordinates = $coordinates->location;
        }

        if ($coordinates instanceof Point) {
            /** @var \Grimzy\LaravelMysqlSpatial\Types\Point $coordinates */
            $latitude = $coordinates->getLat();
            $longitude = $coordinates->getLng();
        }

        if (is_array($coordinates) || is_object($coordinates)) {
            $latitude = static::or($coordinates, ['_lat', 'lat', '_latitude', 'latitude', 'x', '0']);
            $longitude = static::or($coordinates, ['lon', '_lon', 'long', 'lng', '_lng', '_longitude', 'longitude', 'y', '1']);
        }

        if (is_string($coordinates)) {
            $coords = [];

            if (Str::startsWith($coordinates, 'POINT(')) {
                $coordinates = Str::replaceFirst('POINT(', '', $coordinates);
                $coordinates = Str::replace(')', '', $coordinates);
                $coords = explode(' ', $coordinates);

                if (count($coords) !== 2) {
                    return false;
                }

                $coords = array_reverse($coords);
                $coordinates = null;
            }

            if (Str::contains($coordinates, ',')) {
                $coords = explode(',', $coordinates);
            }

            if (Str::contains($coordinates, '|')) {
                $coords = explode('|', $coordinates);
            }

            if (Str::contains($coordinates, ' ')) {
                $coords = explode(' ', $coordinates);
            }

            if (count($coords) !== 2) {
                return false;
            }

            $latitude = static::cleanCoordinateString($coords[0]);
            $longitude = static::cleanCoordinateString($coords[1]);
        }

        return static::isLatitude($latitude) && static::isLongitude($longitude);
    }

    /**
     * Gets a coordinate property from coordinates.
     *
     * @param mixed $coordinates
     * @param string $prop
     * @return boolean
     */
    public static function getCoordFromCoordinates($coordinates, $prop = 'latitude'): float
    {
        $latitude = null;
        $longitude = null;

        if ($coordinates instanceof SpatialExpression) {
            $coordinates = $coordinates->getSpatialValue();
        }

        if ($coordinates instanceof Place) {
            $coordinates = $coordinates->location;
        }

        if ($coordinates instanceof Point) {
            /** @var \Grimzy\LaravelMysqlSpatial\Types\Point $coordinates */
            $latitude = $coordinates->getLat();
            $longitude = $coordinates->getLng();
        } else if (is_array($coordinates) || is_object($coordinates)) {
            $latitude = static::or($coordinates, ['_lat', 'lat', '_latitude', 'latitude', 'x', '0']);
            $longitude = static::or($coordinates, ['lon', '_lon', 'long', 'lng', '_lng', '_longitude', 'longitude', 'y', '1']);
        }

        if (is_string($coordinates)) {
            $coords = [];

            if (Str::startsWith($coordinates, 'POINT(')) {
                $coordinates = Str::replaceFirst('POINT(', '', $coordinates);
                $coordinates = Str::replace(')', '', $coordinates);
                $coords = explode(' ', $coordinates);

                // if (count($coords) !== 2) {
                //     return false;
                // }

                $coords = array_reverse($coords);
                $coordinates = null;
            }

            if (Str::contains($coordinates, ',')) {
                $coords = explode(',', $coordinates);
            }

            if (Str::contains($coordinates, '|')) {
                $coords = explode('|', $coordinates);
            }

            if (Str::contains($coordinates, ' ')) {
                $coords = explode(' ', $coordinates);
            }

            $latitude = $coords[0];
            $longitude = $coords[1];
        }

        return $prop === 'latitude' ? (float) $latitude : (float) $longitude;
    }

    /**
     * Gets latitude property from coordinates.
     *
     * @param mixed $coordinates
     * @return boolean
     */
    public static function getLatitudeFromCoordinates($coordinates): float
    {
        return static::getCoordFromCoordinates($coordinates);
    }

    /**
     * Gets longitude property from coordinates.
     *
     * @param mixed $coordinates
     * @return boolean
     */
    public static function getLongitudeFromCoordinates($coordinates): float
    {
        return static::getCoordFromCoordinates($coordinates, 'longitude');
    }

    /**
     * Gets longitude property from coordinates.
     *
     * @param mixed $coordinates
     * @return \Grimzy\LaravelMysqlSpatial\Types\Point
     */
    public static function getPointFromCoordinates($coordinates): Point
    {
        if ($coordinates instanceof Point) {
            return $coordinates;
        }

        if (!static::isCoordinates($coordinates)) {
            return new Point(0, 0);
        }

        $latitude = static::getLatitudeFromCoordinates($coordinates);
        $longitude = static::getLongitudeFromCoordinates($coordinates);

        return new Point($latitude, $longitude);
    }

    /**
     * Filter an array, removing all null values
     *
     * @param array $arr
     * @return array
     */
    public static function filterArray(array $arr = []): array
    {
        $filteredArray = [];

        foreach ($arr as $key => $el) {
            if ($el !== null) {
                $filteredArray[$key] = $el;
            }
        }

        return $filteredArray;
    }

    /**
     * Delete all of a models relations.
     */
    public static function deleteModels(Collection $models)
    {
        if ($models->count() === 0) {
            return true;
        }

        $ids = $models->map(function ($model) {
            return $model->uuid;
        });

        $instance = app(static::getModelClassName($models->first()));
        $deleted = $instance->whereIn('uuid', $ids)->delete();

        return $deleted;
    }

    /**
     * Convert a point to wkt for sql insert.
     * 
     * @return \Illuminate\Database\Query\Expression
     */
    public static function parsePointToWkt($point)
    {
        $wkt = 'POINT(0 0)';

        if ($point instanceof Point) {
            $wkt = $point->toWKT();
        }

        if (is_array($point)) {
            $json = json_encode($point);
            $p = Point::fromJson($json);

            $wkt = $p->toWkt();
        }

        if (is_string($point)) {
            $p = Point::fromString($point);

            $wkt = $p->toWKT();
        }

        return DB::raw("(ST_PointFromText('$wkt', 0, 'axis-order=long-lat'))");
    }

    /**
     * Get an ordinal formatted number.
     * 
     * @return string
     */
    public static function ordinalNumber($number, $locale = 'en_US')
    {
        $ordinal = new NumberFormatter($locale, NumberFormatter::ORDINAL);
        return $ordinal->format($number);
    }

    public static function serializeJsonResource(JsonResource $resource)
    {
        $request = request();
        $data = $resource->toArray($request);

        foreach ($data as $key => $value) {
            if ($value instanceof JsonResource) {
                $data[$key] = static::serializeJsonResource($value);
            }

            if ($value instanceof Model) {
                $data[$key] = $value->toArray();
            }

            if ($value instanceof Carbon) {
                $data[$key] = $value->toDateTimeString();
            }
        }

        return $data;
    }

    public static function getBase64ImageSize(string $base64ImageString)
    {
        return (int)(strlen(rtrim($base64ImageString, '=')) * 0.75);
    }

    public static function getImageSizeFromString(string $data)
    {
        $data = static::isBase64($data) ? base64_decode($data) : $data;
        $uri = 'data://application/octet-stream;base64,' . $data;

        return getimagesize($uri);
    }

    public static function isBase64(string $data)
    {
        return (bool) preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data);
    }

    public static function rawPointToFloatPair($data)
    {
        $res = unpack("lSRID/CByteOrder/lTypeInfo/dX/dY", $data);
        return [$res['X'], $res['Y']];
    }

    public static function rawPointToPoint($data)
    {
        $res = unpack("lSRID/CByteOrder/lTypeInfo/dX/dY", $data);
        return new Point($res['X'], $res['Y'], $res['SRID']);
    }

    /**
     * Generates a public id given a type.
     *
     * @return string
     */
    public static function generatePublicId(string $type)
    {
        $hashid = lcfirst(Hashids::encode(time(), rand(), rand()));
        $hashid = substr($hashid, 0, 7);

        return $type . '_' . $hashid;
    }

    /**
     * Calculates driving distance and time using Google distance matrix.
     * Returns distance in meters and time in seconds.
     * 
     * @param Place|Point|array $origin
     * @param Place|Point|array $destination
     * 
     * @return stdObject
     */
    public static function getDrivingDistanceAndTime($origin, $destination)
    {
        if ($origin instanceof Place) {
            $origin = static::createObject([
                'latitude' => $origin->location->getLat(),
                'longitude' => $origin->location->getLng(),
            ]);
        } else {
            $point = static::getPointFromCoordinates($origin);
            $origin = static::createObject([
                'latitude' => $point->getLat(),
                'longitude' => $point->getLng(),
            ]);
        }

        if ($destination instanceof Place) {
            $destination = static::createObject([
                'latitude' => $destination->location->getLat(),
                'longitude' => $destination->location->getLng(),
            ]);
        } else {
            $point = static::getPointFromCoordinates($destination);
            $destination = static::createObject([
                'latitude' => $point->getLat(),
                'longitude' => $point->getLng(),
            ]);
        }

        $cacheKey = $origin->latitude . ':' . $origin->longitude . ':' . $destination->latitude . ':' . $destination->longitude;

        // check cache for results
        $cachedResult = Redis::get($cacheKey);

        if ($cachedResult) {
            $json = json_decode($cachedResult);

            return $json;
        }

        $curl = new \Curl\Curl();
        $curl->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => $origin->latitude . ',' . $origin->longitude,
            'destinations' => $destination->latitude . ',' . $destination->longitude,
            'mode' => 'driving',
            'key' => env('GOOGLE_MAPS_API_KEY')
        ]);

        $response = $curl->response;
        $distance = static::get($response, 'rows.0.elements.0.distance.value');
        $time = static::get($response, 'rows.0.elements.0.duration.value');

        $result = static::createObject([
            'distance' => $distance,
            'time' => $time
        ]);

        // cache result
        Redis::set($cacheKey, json_encode($result));

        return $result;
    }

    /**
     * Calculates driving distance and time using Google distance matrix for multiple origins or destinations.
     * Returns distance in meters and time in seconds.
     * 
     * @param Place|Point|array $origin
     * @param Place|Point|array $destination
     * 
     * @return stdObject
     */
    public static function distanceMatrix($origins = [], $destinations = [])
    {
        $origins = collect($origins)->map(function ($origin) {
            $point = static::getPointFromCoordinates($origin);
            $origin = static::createObject([
                'latitude' => $point->getLat(),
                'longitude' => $point->getLng(),
            ]);

            return $origin;
        });

        $destinations = collect($destinations)->map(function ($destination) {
            $point = static::getPointFromCoordinates($destination);
            $destination = static::createObject([
                'latitude' => $point->getLat(),
                'longitude' => $point->getLng(),
            ]);

            return $destination;
        });

        // get url ready string for origins
        $originsString = $origins->map(function ($origin) {
            return $origin->latitude . ',' . $origin->longitude;
        })->join('|');

        // get url ready string for origins
        $destinationString = $destinations->map(function ($destination) {
            return $destination->latitude . ',' . $destination->longitude;
        })->join('|');

        $cacheKey = md5($originsString . '_' . $destinationString);

        // check cache for results
        $cachedResult = Redis::get($cacheKey);

        if ($cachedResult) {
            $json = json_decode($cachedResult);

            return $json;
        }

        $curl = new \Curl\Curl();
        $curl->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => $originsString,
            'destinations' => $destinationString,
            'mode' => 'driving',
            'key' => env('GOOGLE_MAPS_API_KEY')
        ]);

        $response = $curl->response;
        $distance = static::get($response, 'rows.0.elements.0.distance.value');
        $time = static::get($response, 'rows.0.elements.0.duration.value');

        $result = static::createObject([
            'distance' => $distance,
            'time' => $time
        ]);

        // cache result
        Redis::set($cacheKey, json_encode($result));

        return $result;
    }

    public static function getPreliminaryDistanceMatrix($origin, $destination)
    {
        $origin = $origin instanceof Place ? $origin->location : static::getPointFromCoordinates($origin);
        $destination = $destination instanceof Place ? $destination->location : static::getPointFromCoordinates($destination);

        $distance = Utils::vincentyGreatCircleDistance($origin, $destination);
        $time = round($distance / 100) * 7.2;

        return static::createObject([
            'distance' => $distance,
            'time' => $time
        ]);
    }

    public static function formatMeters($meters, $abbreviate = true)
    {
        if ($meters > 1000) {
            return round($meters / 1000, 2) . ($abbreviate ? 'km' : ' kilometers');
        }

        return round($meters) . ($abbreviate ? 'm' : ' meters');
    }

    /**
     * Calculates the great-circle distance between two points, with
     * the Vincenty formula. (Using over haversine tdue to antipodal point issues)
     * 
     * https://en.wikipedia.org/wiki/Great-circle_distance#Formulas
     * https://en.wikipedia.org/wiki/Antipodal_point
     * 
     * @param \Grimzy\LaravelMysqlSpatial\Types\Point Starting point
     * @param \Grimzy\LaravelMysqlSpatial\Types\Point Ending point
     * @param float $earthRadius Mean earth radius in [m]
     * @return float Distance between points in [m] (same as earthRadius)
     */
    public static function vincentyGreatCircleDistance(Point $from, Point $to, float $earthRadius = 6371000): float
    {
        // convert from degrees to radians
        $latFrom = deg2rad($from->getLat());
        $lonFrom = deg2rad($from->getLng());
        $latTo = deg2rad($to->getLat());
        $lonTo = deg2rad($to->getLng());

        $lonDelta = $lonTo - $lonFrom;
        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);
        return $angle * $earthRadius;
    }

    /**
     * Finds the newarest timezone for coordinate points.
     *
     * @param Point $location
     * @param string $country_code
     * @return string 
     */
    public static function getNearestTimezone(Point $location, $country_code = ''): string
    {
        $timezone_ids = ($country_code) ? DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country_code)
            : DateTimeZone::listIdentifiers();

        $cur_lat = $location->getLat();
        $cur_long = $location->getLng();

        if ($timezone_ids && is_array($timezone_ids) && isset($timezone_ids[0])) {

            $time_zone = '';
            $tz_distance = 0;

            //only one identifier?
            if (count($timezone_ids) == 1) {
                $time_zone = $timezone_ids[0];
            } else {

                foreach ($timezone_ids as $timezone_id) {
                    $timezone = new DateTimeZone($timezone_id);
                    $location = $timezone->getLocation();
                    $tz_lat   = $location['latitude'];
                    $tz_long  = $location['longitude'];

                    $theta    = $cur_long - $tz_long;
                    $distance = (sin(deg2rad($cur_lat)) * sin(deg2rad($tz_lat)))
                        + (cos(deg2rad($cur_lat)) * cos(deg2rad($tz_lat)) * cos(deg2rad($theta)));
                    $distance = acos($distance);
                    $distance = abs(rad2deg($distance));

                    if (!$time_zone || $tz_distance > $distance) {
                        $time_zone   = $timezone_id;
                        $tz_distance = $distance;
                    }
                }
            }
            return  $time_zone;
        }

        return 'unknown';
    }

    public static function formatSeconds($seconds)
    {
        return Carbon::now()->addSeconds($seconds)->longAbsoluteDiffForHumans();
    }

    public static function isEmail($email)
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function convertDb($connection, $charset, $collate, $dryRun)
    {
        $dbName = config("database.connections.{$connection}.database");

        $varchars = DB::connection($connection)
            ->select(DB::raw("select * from INFORMATION_SCHEMA.COLUMNS where DATA_TYPE = 'varchar' and (CHARACTER_SET_NAME != '{$charset}' or COLLATION_NAME != '{$collate}') AND TABLE_SCHEMA = '{$dbName}'"));
        // Check if shrinking field size will truncate!
        $skip = [];  // List of table.column that will be handled manually
        $indexed = [];
        if ($charset == 'utf8mb4') {
            $error = false;
            foreach ($varchars as $t) {
                if ($t->CHARACTER_MAXIMUM_LENGTH > 191) {
                    $key = "{$t->TABLE_NAME}.{$t->COLUMN_NAME}";

                    // Check if column is indexed
                    $index = DB::connection($connection)
                        ->select(DB::raw("SHOW INDEX FROM `{$t->TABLE_NAME}` where column_name = '{$t->COLUMN_NAME}'"));
                    $indexed[$key] = count($index) ? true : false;

                    if (count($index)) {
                        $result = DB::connection($connection)
                            ->select(DB::raw("select count(*) as `count` from `{$t->TABLE_NAME}` where length(`{$t->COLUMN_NAME}`) > 191"));
                        if ($result[0]->count > 0) {
                            echo "-- DATA TRUNCATION: {$t->TABLE_NAME}.{$t->COLUMN_NAME}({$t->CHARACTER_MAXIMUM_LENGTH}) => {$result[0]->count}" . PHP_EOL;
                            if (!in_array($key, $skip)) {
                                $error = true;
                            }
                        }
                    }
                }
            }
            if ($error) {
                throw new \Exception('Aborting due to data truncation');
            }
        }

        $query = "SET FOREIGN_KEY_CHECKS = 0";
        static::dbExec($query, $dryRun, $connection);

        $query = "ALTER SCHEMA {$dbName} DEFAULT CHARACTER SET {$charset} DEFAULT COLLATE {$collate}";
        static::dbExec($query, $dryRun, $connection);

        $tableChanges = [];
        foreach ($varchars as $t) {
            $key = "{$t->TABLE_NAME}.{$t->COLUMN_NAME}";
            if (!in_array($key, $skip)) {
                if ($charset == 'utf8mb4' && $t->CHARACTER_MAXIMUM_LENGTH > 191 && $indexed["{$t->TABLE_NAME}.{$t->COLUMN_NAME}"]) {
                    $tableChanges["{$t->TABLE_NAME}"][] = "CHANGE `{$t->COLUMN_NAME}` `{$t->COLUMN_NAME}` VARCHAR(191) CHARACTER SET {$charset} COLLATE {$collate}";
                    echo "-- Shrinking: {$t->TABLE_NAME}.{$t->COLUMN_NAME}({$t->CHARACTER_MAXIMUM_LENGTH})" . PHP_EOL;
                } else if ($charset == 'utf8' && $t->CHARACTER_MAXIMUM_LENGTH == 191) {
                    $tableChanges["{$t->TABLE_NAME}"][] = "CHANGE `{$t->COLUMN_NAME}` `{$t->COLUMN_NAME}` VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collate}";
                    echo "-- Expanding: {$t->TABLE_NAME}.{$t->COLUMN_NAME}({$t->CHARACTER_MAXIMUM_LENGTH})";
                } else {
                    $tableChanges["{$t->TABLE_NAME}"][] = "CHANGE `{$t->COLUMN_NAME}` `{$t->COLUMN_NAME}` VARCHAR({$t->CHARACTER_MAXIMUM_LENGTH}) CHARACTER SET {$charset} COLLATE {$collate}";
                }
            }
        }

        $texts = DB::connection($connection)
            ->select(DB::raw("select * from INFORMATION_SCHEMA.COLUMNS where DATA_TYPE like '%text%' and (CHARACTER_SET_NAME != '{$charset}' or COLLATION_NAME != '{$collate}') AND TABLE_SCHEMA = '{$dbName}'"));
        foreach ($texts as $t) {
            $tableChanges["{$t->TABLE_NAME}"][] = "CHANGE `{$t->COLUMN_NAME}` `{$t->COLUMN_NAME}` {$t->DATA_TYPE} CHARACTER SET {$charset} COLLATE {$collate}";
        }

        $tables = DB::connection($connection)
            ->select(DB::raw("select * from INFORMATION_SCHEMA.TABLES where TABLE_COLLATION != '{$collate}' and TABLE_SCHEMA = '{$dbName}';"));
        foreach ($tables as $t) {
            $tableChanges["{$t->TABLE_NAME}"][] = "CONVERT TO CHARACTER SET {$charset} COLLATE {$collate}";
            $tableChanges["{$t->TABLE_NAME}"][] = "DEFAULT CHARACTER SET={$charset} COLLATE={$collate}";
        }

        foreach ($tableChanges as $table => $changes) {
            $query = "ALTER TABLE `{$table}` " . implode(",\n", $changes);
            static::dbExec($query, $dryRun, $connection);
        }

        $query = "SET FOREIGN_KEY_CHECKS = 1";
        static::dbExec($query, $dryRun, $connection);

        echo "-- {$dbName} CONVERTED TO {$charset}-{$collate}" . PHP_EOL;
    }

    public static function dbExec($query, $dryRun, $connection)
    {
        if ($dryRun) {
            echo $query . ';' . PHP_EOL;
        } else {
            DB::connection($connection)->getPdo()->exec($query);
        }
    }

    public static function numberAsWord(int $number): string
    {
        $formatter = new NumberFormatter("en", NumberFormatter::SPELLOUT);

        return $formatter->format($number);
    }

    public static function numericStringToDigits(string $number): string
    {
        // Replace all number words with an equivalent numeric value
        $data = strtr(
            $number,
            array(
                'zero'      => '0',
                'a'         => '1',
                'one'       => '1',
                'two'       => '2',
                'three'     => '3',
                'four'      => '4',
                'five'      => '5',
                'six'       => '6',
                'seven'     => '7',
                'eight'     => '8',
                'nine'      => '9',
                'ten'       => '10',
                'eleven'    => '11',
                'twelve'    => '12',
                'thirteen'  => '13',
                'fourteen'  => '14',
                'fifteen'   => '15',
                'sixteen'   => '16',
                'seventeen' => '17',
                'eighteen'  => '18',
                'nineteen'  => '19',
                'twenty'    => '20',
                'thirty'    => '30',
                'forty'     => '40',
                'fourty'    => '40', // common misspelling
                'fifty'     => '50',
                'sixty'     => '60',
                'seventy'   => '70',
                'eighty'    => '80',
                'ninety'    => '90',
                'hundred'   => '100',
                'thousand'  => '1000',
                'million'   => '1000000',
                'billion'   => '1000000000',
                'and'       => '',
            )
        );

        // Coerce all tokens to numbers
        $parts = array_map(
            function ($val) {
                return floatval($val);
            },
            preg_split('/[\s-]+/', $data)
        );

        $stack = new SplStack; // Current work stack
        $sum   = 0; // Running total
        $last  = null;

        foreach ($parts as $part) {
            if (!$stack->isEmpty()) {
                // We're part way through a phrase
                if ($stack->top() > $part) {
                    // Decreasing step, e.g. from hundreds to ones
                    if ($last >= 1000) {
                        // If we drop from more than 1000 then we've finished the phrase
                        $sum += $stack->pop();
                        // This is the first element of a new phrase
                        $stack->push($part);
                    } else {
                        // Drop down from less than 1000, just addition
                        // e.g. "seventy one" -> "70 1" -> "70 + 1"
                        $stack->push($stack->pop() + $part);
                    }
                } else {
                    // Increasing step, e.g ones to hundreds
                    $stack->push($stack->pop() * $part);
                }
            } else {
                // This is the first element of a new phrase
                $stack->push($part);
            }

            // Store the last processed part
            $last = $part;
        }

        return $sum + $stack->pop();
    }

    public static function bindVariablesToString(string $template, array $vars = [])
    {
        return preg_replace_callback('/{(.+?)}/', function ($matches) use ($vars) {
            return Utils::get($vars, $matches[1]) ?? '#null';
        }, $template);
    }

    public static function resolveSubject(string $publicId)
    {
        $resourceMap = [
            'store' => 'store:storefront',
            'product' => 'store:storefront',
            'order' => 'order',
            'customer' => 'contact',
            'contact' => 'contact'
        ];

        list($type) = explode('_', $publicId);

        $modelNamespace = static::getMutationType($resourceMap[$type]);

        if ($modelNamespace) {
            return app($modelNamespace)->where('public_id', $publicId)->first();
        }

        return null;
    }

    public static function unicodeDecode($str)
    {
        $str = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);

        return $str;
    }

    public static function isUnicodeString($string)
    {
        return is_string($string) && strlen($string) != strlen(utf8_decode($string));
    }

    public static function findDelimiterFromString(?string $string, $fallback = ',')
    {
        if (!is_string($string)) {
            return $fallback;
        }

        $delimiters = ['|', ','];
        $score = [];

        foreach ($delimiters as $delimiter) {
            if (Str::contains($string, $delimiter)) {
                $score[$delimiter] = Str::substrCount($string, $delimiter);
            }
        }

        $result = collect($score)->sortDesc()->keys()->first();

        return $result ?? $fallback;
    }

    /**
     * @param string $path
     * @param string $type
     * @param \Fleetbase\Models\Model $owner
     * @return null|\Fleetbase\Models\File
     */
    public static function urlToStorefrontFile($url, $type = 'source', ?Model $owner = null)
    {
        if (!is_string($url)) {
            return null;
        }

        if (empty($url)) {
            return null;
        }

        if (!Str::startsWith($url, 'http')) {
            return null;
        }

        try {
            $contents = file_get_contents($url);
        } catch (ErrorException $e) {
            return null;
        }
        
        $defaultExtensionGuess = '.jpg';

        if (!$contents) {
            return null;
        }

        // parsed path
        $path = urldecode(parse_url($url, PHP_URL_PATH));
        $fileName = basename($path);
        $fileNameInfo = pathinfo($fileName);

        // if no file extension use guess extension
        if (!isset($fileNameInfo['extension'])) {
            $fileName .= $defaultExtensionGuess;
        }

        $bucketPath = 'uploads/storefront/' . $owner->uuid . '/' . Str::slug($type) . '/' . $fileName;
        $pathInfo = pathinfo($bucketPath);

        // upload to bucket
        Storage::disk('s3')->put($bucketPath, $contents, 'public');

        $fileInfo = [
            'company_uuid' => $owner->company_uuid ?? null,
            'uploader_uuid' => $owner->uuid,
            // 'name' => $pathInfo['filename'],
            'original_filename' => $fileName,
            // 'extension' => $pathInfo['extension'],
            'content_type' => File::getFileMimeType($pathInfo['extension']),
            'path' => $bucketPath,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'type' => Str::slug($type, '_'),
            'file_size' => Utils::getBase64ImageSize($contents)
        ];

        if ($owner) {
            $fileInfo['key_uuid'] = $owner->uuid;
            $fileInfo['key_type'] = Utils::getMutationType($owner);
        }

        // create file 
        $file = File::create($fileInfo);

        return $file;
    }

    public static function isSubscriptionValidForAction(Request $request): bool
    {
        $company = Company::where('uuid', session('company'))->first();

        if (!$company) {
            return false;
        }

        $guarded = config('api.subscription_required_endpoints');
        $method = strtolower($request->method());
        $endpoint = strtolower(last($request->segments()));

        $current = $method . ':' . $endpoint;

        // if attempting to hit a guarded api check and validate company is subscribed
        if (in_array($current, $guarded)) {
            return $company->subscribed('standard') || $company->onTrial();
        }

        return true;
    }

    public static function getEventsQueue(): string
    {
        $sqs_events_queue = env('SQS_EVENTS_QUEUE', 'events');

        if ($queueUrl = getenv('QUEUE_URL_EVENTS')) {
            $url = parse_url($queueUrl);
            $sqs_events_queue = basename($url['path']);
        }

        return $sqs_events_queue;
    }

    /**
     * Converts a string or class name to an ember resource type \Fleetbase\Models\IntegratedVendor -> integrated-vendor
     * @param string $className
     * @return null|string
     */
    public static function toEmberResourceType($className)
    {
        if (!is_string($className)) {
            return null;
        }

        $baseClassName = static::classBasename($className);
        $emberResourceType = Str::snake($baseClassName, '-');

        return $emberResourceType;
    }

    public static function isIntegratedVendorId(string $id)
    {
        if (Str::startsWith($id, 'integrated_vendor_')) {
            return true;
        }

        $providerIds = DB::table('integrated_vendors')->select('provider')->where('company_uuid', session('company'))->distinct()->get()->map(function ($result) {
            return $result->provider;
        })->toArray();

        return in_array($id, $providerIds);
    }
}
