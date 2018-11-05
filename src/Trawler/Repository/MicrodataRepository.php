<?php

namespace Trawler\Repository;

use \MongoDB\Client;
use \MongoDB\Collection;

class MicrodataRepository
{
    const DIGEST_ALGORITHM = 'fnv1a64';

    /** @var Client */
    private $client;
    /** @var Collection */
    private $collection;

    /**
     * Takes an array of options for connecting to the database
     *
     * $databseOptions example: [
     *      'host' => '127.0.0.1',
     *      'port' => 27017,
     *      'database' => 'trawler',
     *      'username' => 'trawler',
     *      'password' => 'somepassword',
     *      'collection' => 'microdata']
     *
     * @param array $databseOptions options for connecting to the database
     */
    public function __construct($databseOptions)
    {
        extract($databseOptions);
        $this->client = new Client("mongodb://$username@$host:$port/$database", [
            'password' => urlencode($password)
        ]);
        $this->collection = $this->client->$database->$collection;
    }

    public function insertMicrodata($url, $microdata)
    {
        $data = $microdata;
        if (!empty($data)) {
            $update = [];
            foreach ($data as $document) {
                $preparedDocument = $this->prepareDocument($document);
                $update[] = ['updateOne' => [
                        ['_digest' => $preparedDocument->_digest],
                        ['$set' => $preparedDocument],
                        ['upsert' => true]
                    ]];
            }
            $result = $this->collection->bulkWrite($update);
            return $result;
        }
    }

    private function prepareDocument(\stdClass $document) : \stdClass
    {
        $cleanedJson = $this->stripNamespaceUris(json_encode($document));
        $cleanedDocument = json_decode($cleanedJson);
        $cleanedDocument->_digest = hash(self::DIGEST_ALGORITHM, $cleanedJson);
        return $cleanedDocument;
    }

    private function stripNamespaceUris(string $text) : string
    {
        return preg_replace(
            '#"https?:[^"]*/([^"/]+)"\s?:#iu',
            '"$1":',
            $text
        );
    }

    public function getItemTypeCounts()
    {
        return $this->collection->aggregate([
            ['$group' => ['_id' => '$types', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1]]
        ]);
    }
}
