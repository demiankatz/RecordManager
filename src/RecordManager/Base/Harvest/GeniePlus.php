<?php
/**
 * GeniePlus API Harvesting Class
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2016-2021.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Base\Harvest;

/**
 * GeniePlus Class
 *
 * This class harvests records via the GeniePlus REST API using settings from
 * datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class GeniePlus extends AbstractBase
{
    /**
     * Database name for the API
     *
     * @var string
     */
    protected $database;

    /**
     * Template containing MARC records
     *
     * @var string
     */
    protected $template;

    /**
     * OAuth ID for the API
     *
     * @var string
     */
    protected $oauthId;

    /**
     * Username for the API
     *
     * @var string
     */
    protected $username;

    /**
     * Password for the API
     *
     * @var string
     */
    protected $password;

    /**
     * Access token to the API
     *
     * @var string
     */
    protected $accessToken = null;

    /**
     * Harvesting start position
     *
     * @var int
     */
    protected $startPosition = 0;

    /**
     * Number of records to request in one query. A too high number may result in
     * error from the API or the request taking indefinitely long.
     *
     * @var int
     */
    protected $batchSize = 100;

    /**
     * HTTP client options
     *
     * @var array
     */
    protected $httpOptions = [
        'timeout' => 600
    ];

    /**
     * Initialize harvesting
     *
     * @param string $source    Source ID
     * @param bool   $verbose   Verbose mode toggle
     * @param bool   $reharvest Whether running a reharvest
     *
     * @return void
     */
    public function init(string $source, bool $verbose, bool $reharvest): void
    {
        parent::init($source, $verbose, $reharvest);

        $settings = $this->dataSourceConfig[$source] ?? [];
        if (empty($settings['genieplusDatabase'])
            || empty($settings['geniePlusOauthId'])
            || empty($settings['geniePlusUsername'])
            || empty($settings['geniePlusPassword'])
        ) {
            throw new \Exception(
                'Required GeniePlus setting missing from settings'
            );
        }
        $this->database = $settings['genieplusDatabase'];
        $this->template = $settings['genieplusTemplate'] ?? 'Catalog';
        $this->oauthId = $settings['geniePlusOauthId'];
        $this->username = $settings['geniePlusUsername'];
        $this->password = $settings['geniePlusPassword'];
        $this->batchSize = $settings['batchSize'] ?? 100;
    }

    /**
     * Override the start position.
     *
     * @param string $pos New start position
     *
     * @return void
     */
    public function setInitialPosition($pos)
    {
        $this->startPosition = intval($pos);
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    public function harvest($callback)
    {
        $this->initHarvest($callback);

        $harvestStartTime = $this->getHarvestStartTime();
        $apiParams = [
            'page-size' => $this->batchSize,
            'page' => floor($this->startPosition / $this->batchSize),
            'fields' => 'MarcRecord',
            'command' => "DtTmModifd > '1/1/1980 1:00:00 PM' sortby DtTmModifd"
        ];

        if (!empty($this->startDate) || !empty($this->endDate)) {
            // TODO: implement date range support
            $startDate = $this->startDate;
            $endDate = $this->endDate;
            $this->infoMsg("Incremental harvest: $startDate-$endDate");
        } else {
            $this->infoMsg('Initial harvest for all records');
        }

        // Keep harvesting as long as a records are received:
        do {
            $response = $this->sendRequest(
                ['_rest', 'databases', $this->database, 'templates', $this->template, 'search-result'],
                $apiParams
            );
            $count = $this->processResponse($response->getBody());
            $this->reportResults();
            $apiParams['page']++;
        } while ($count > 0);

        if (empty($this->endDate)) {
            $this->saveLastHarvestedDate(
                gmdate('Y-m-d\TH:i:s\Z', $harvestStartTime)
            );
        }
    }

    /**
     * Get server date as a unix timestamp
     *
     * @return int
     */
    protected function getHarvestStartTime()
    {
        $result = time();
        return $result;
    }

    /**
     * Make a request and return the response as a string
     *
     * @param array $path   Sierra API path
     * @param array $params GET parameters for the method
     *
     * @return \HTTP_Request2_Response
     * @throws \Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function sendRequest($path, $params)
    {
        // Set up the request:
        $apiUrl = $this->baseURL;

        foreach ($path as $value) {
            $apiUrl .= '/' . urlencode($value);
        }

        $request = $this->httpClientManager->createClient(
            $apiUrl,
            \HTTP_Request2::METHOD_GET,
            $this->httpOptions
        );
        $request->setHeader('Accept', 'application/json');

        // Load request parameters:
        $url = $request->getURL();
        $url->setQueryVariables($params);
        $urlStr = $url->getURL();

        if (null === $this->accessToken) {
            $this->renewAccessToken();
        }
        $request->setHeader(
            'Authorization',
            "Bearer {$this->accessToken}"
        );

        // Perform request and throw an exception on error:
        $maxTries = $this->maxTries;
        for ($try = 1; $try <= $maxTries; $try++) {
            $this->infoMsg("Sending request: $urlStr");
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code == 404) {
                    return $response;
                }
                if ($code == 401) {
                    $this->infoMsg('Renewing access token');
                    $this->renewAccessToken();
                    $request->setHeader(
                        'Authorization',
                        "Bearer {$this->accessToken}"
                    );
                    ++$maxTries;
                    sleep(1);
                    continue;
                }
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$urlStr' failed ($code: "
                            . $response->getBody() . '), retrying in '
                            . "{$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg("Request '$urlStr' failed: $code");
                    throw new \Exception("Request failed: $code");
                }

                return $response;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw $e;
            }
        }
        throw new \Exception('Request failed');
    }

    /**
     * Process the API response.
     * Throw exception if an error is detected.
     *
     * @param string $response Sierra response JSON
     *
     * @return int Count of records processed
     * @throws \Exception
     */
    protected function processResponse($response)
    {
        var_dump($response);
        /* TODO
        $this->infoMsg('Processing received records');
        if (empty($response)) {
            return 0;
        }
        $json = json_decode($response, true);
        if (isset($json['ErrorCodes'])) {
            $this->errorMsg(
                'Sierra API returned error: '
                . $json['ErrorCodes']['code'] . ' ' . $json['ErrorCodes']['name']
                . ': ' . $json['ErrorCodes']['description']
            );
            throw new \Exception(
                '{$this->source}: Server returned error: '
                . $json['ErrorCodes']['code'] . ' ' . $json['ErrorCodes']['name']
                . ': ' . $json['ErrorCodes']['description']
            );
        }

        if (!isset($json['entries'])) {
            return 0;
        }

        $count = 0;
        foreach ($json['entries'] as $record) {
            ++$count;
            $id = $record['id'];
            $oaiId = $this->createOaiId($this->source, $id);
            $deleted = $this->isDeleted($record);
            if ($deleted) {
                call_user_func($this->callback, $this->source, $oaiId, true, null);
                $this->deletedRecords++;
            } else {
                $this->changedRecords += call_user_func(
                    $this->callback,
                    $this->source,
                    $oaiId,
                    false,
                    $this->convertRecordToMarcArray($record)
                );
            }
        }
        return $count;
        */
        return 0;
    }

    /**
     * Renew the access token. Throw an exception if there is an error.
     *
     * @return void
     * @throws \Exception
     * @throws \HTTP_Request2_LogicException
     */
    protected function renewAccessToken()
    {
        // Set up the request:
        $apiUrl = $this->baseURL . '/_oauth/token';
        $request = $this->httpClientManager->createClient(
            $apiUrl,
            \HTTP_Request2::METHOD_POST
        );
        $request->setHeader('Accept', 'application/json');
        // TODO: encode properly
        $request->setBody('client_id=' . $this->oauthId . '&grant_type=password&database=' . $this->database . '&username=' . $this->username . '&password=' . $this->password);

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= $this->maxTries; $try++) {
            $this->infoMsg("Sending request: $apiUrl");
            try {
                $response = $request->send();
                $code = $response->getStatus();
                if ($code >= 300) {
                    if ($try < $this->maxTries) {
                        $this->warningMsg(
                            "Request '$apiUrl' failed ($code: "
                            . $response->getBody() . '), retrying in'
                            . " {$this->retryWait} seconds..."
                        );
                        sleep($this->retryWait);
                        continue;
                    }
                    $this->fatalMsg(
                        "Request '$apiUrl' failed ($code: " . $response->getBody()
                        . ')'
                    );
                    throw new \Exception("Access token request failed: $code");
                }

                $json = json_decode($response->getBody(), true);
                if (empty($json['access_token'])) {
                    throw new \Exception(
                        'No access token in response: ' . $response->getBody()
                    );
                }
                $this->accessToken = $json['access_token'];
                break;
            } catch (\Exception $e) {
                if ($try < $this->maxTries) {
                    $this->warningMsg(
                        "Request '$apiUrl' failed (" . $e->getMessage()
                        . "), retrying in {$this->retryWait} seconds..."
                    );
                    sleep($this->retryWait);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return "genieplus:$sourceId:$id";
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->infoMsg(
            'Harvested ' . $this->changedRecords . ' normal and '
            . $this->deletedRecords . ' deleted records'
        );
    }
}
