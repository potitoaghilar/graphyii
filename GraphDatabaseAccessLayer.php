<?php

namespace app\helpers;

use Yii;
use GuzzleHttp;
use yii\helpers\Json;

class GraphDatabaseAccessLayer
{

    /**
     * @param $graphqlSchema String GraphQL schema filename. Example: Models.graphql
     * @return string
     */
    public static function rebuildSchemaModels($graphqlSchema) {

        $modelsPath = Yii::getAlias('@app/models/');

        // Get schema data from
        $schema = file_get_contents($modelsPath . $graphqlSchema);

        // Normalize datas and extract types
        $types = array_filter(explode('}', preg_replace(['/\([^)]+\)/'], '', str_replace(['type ', '{', ' ', "\n\n"], '', $schema))));
        for ($i = 0; $i < count($types); $i++) {
            $types[$i] = rtrim($types[$i]);
        }

        // Create model classes
        foreach ($types as $type) {
            self::createModel($type);
        }

        // Return status
        return Json::encode([
            'status' => 'success',
        ]);

    }

    private static function createModel($type) {

        $data = explode("\n", $type);

        // Get className
        $className = $data[0];
        unset($data[0]);

        // Get attributes
        $attributes = '';
        foreach ($data as $attribute) {
            $attributes .= "\tpublic $" . explode(':', $attribute)[0] . ";\n";
        }

        // Generate template
        $template = "<?php\n\nclass $className {\n\n$attributes\n}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/' . $className . '.php'), $template);

    }

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
        return Json::decode($res->getBody()->getContents())['data'];
    }

    public static function mutation($gql) {
        // TODO
    }

}