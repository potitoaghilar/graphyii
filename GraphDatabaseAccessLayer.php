<?php

namespace app\helpers;

use Yii;
use GuzzleHttp;
use yii\helpers\Json;

class GraphDatabaseAccessLayer
{

    /**
     * @param $gql String GraphQL query
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function query($gql) {
        $client = new GuzzleHttp\Client();
        $res = $client->request('POST', Yii::$app->params['api_endpoint'], [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(Yii::$app->params['db_username'] . ':' . Yii::$app->params['db_password']),
            ],
            GuzzleHttp\RequestOptions::JSON => [
                'operationName' => null,
                'variables' => [],
                'query' => $gql,
            ],
        ]);
        return Json::decode($res->getBody()->getContents());
    }

}