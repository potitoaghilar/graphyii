<?php

namespace app\helpers;

use Yii;
use GuzzleHttp;
use GraphAware\Neo4j\Client\ClientBuilder;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use Exception;

class GraphDatabaseAccessLayer
{

    /**
     * Build GraphQL schema models and deploy to database schema provided
     * @param $graphqlSchema String
     * @throws \yii\base\Exception
     */
    public static function buildSchema($graphqlSchema) {
        self::updateDatabaseGraph($graphqlSchema);
        self::buildSchemaModels($graphqlSchema);
    }

    /**
     * @param $graphqlSchema String GraphQL schema filename. Example: Models.graphql
     * @return string
     * @throws \yii\base\Exception
     */
    public static function buildSchemaModels($graphqlSchema) {

        $modelsPath = Yii::getAlias('@app/models/');

        // Get schema data from
        $schema = file_get_contents($modelsPath . $graphqlSchema);

        // Normalize datas and extract types
        $types = self::extractTypes($schema);

        // Create path if not exists
        FileHelper::createDirectory($modelsPath . 'graphql', $mode = 0775, $recursive = true);

        // Create model classes
        foreach ($types as $type) {
            self::createModel($type, self::extractTypeNames($types));
        }

        // Return status
        return Json::encode([
            'status' => 'success',
        ]);

    }

    /**
     * Extract types from provided schema
     * @param $schema
     * @return array
     */
    private static function extractTypes($schema) {

        $types = [];

        // Normalize and split data
        $entries = preg_split("/}\n|}$/", $schema);
        for($i = 0; $i < count($entries); $i++) {
            $entries[$i] = ltrim(rtrim(str_replace("\n\n", "\n", $entries[$i])));
        }

        // Second normalization
        foreach ($entries as $entry) {

            // Check if entry is a type or an interface
            if(substr($entry, 0, 5) == 'type ' || substr($entry, 0, 10) == 'interface ') {

                // Regex:
                // - content in parenthesis
                $parenthesis = '\([^)]+\)';
                // - decorators
                $decorators = '@.*';
                // - extension and implementation
                $typeExtImpl = '(implements|extends).+';
                // - remaining characters
                $other = 'type |interface |{| ';

                // Formatted data
                $formatted = preg_replace(["/$parenthesis|$decorators|$typeExtImpl|$other/"], '', $entry);
                $formattedParts = explode("\n", $formatted, 2);
                $attributes = [];
                foreach (explode("\n", $formattedParts[1]) as $parameter) {

                    // Build parameter
                    $paramParts = explode(':', $parameter);
                    $attributes[] = [
                        'name' => lcfirst($paramParts[0]),
                        'type' => self::typeConversion(str_replace('!', '', $paramParts[1]), true), // Remove required field: !
                        'isArray' => substr($paramParts[1], 0, 1) == '[',
                        'required' => substr($paramParts[1], -1) == '!',
                        'visibility' => 'public', // TODO this can be extended in future
                    ];

                }

                // Create new type
                $types[] = [
                    'className' => $formattedParts[0],
                    'attributes' => $attributes,
                ];

            }

        }

        return $types;
    }

    private static function extractTypeNames($types) {
        $allTypeNames = [];

        foreach ($types as $type) {
            $allTypeNames[] = $type['className'];
        }

        return $allTypeNames;
    }

    private static function createModel($type, $allTypeNames) {

        // Get className
        $className = $type['className'];

        // Get attributes and generate methods
        $attributes = '';
        $methods = '';
        $constructorParams = '';
        $constructorDirectives = '';
        $constructorParamsCount = 0;
        foreach ($type['attributes'] as $attribute) {

            // Generate documentation and attribute
            $required = '';
            if($attribute['required']) {
                $required = "\n\t * @required";
            }
            $documentation = "\t/**\n\t * @var ${attribute['type']}" . ($attribute['isArray'] ? '[]' : '') . "$required\n\t */";
            $attributes .= "\n\n$documentation\n\t${attribute['visibility']} \$${attribute['name']};";


            // Check if type is standard or GraphModelType
            $isGraphModelType = in_array($attribute['type'], $allTypeNames);

            // Generate methods
            if($isGraphModelType) {
                $methods .= "\n\n\tpublic function get" . ucfirst($attribute['name']) . "Class() { return ${attribute['type']}::getClass(); }";
            }

            // Check if is a standard type
            if(!$isGraphModelType) {

                // Check if required
                $nullValue = '';
                if(!$attribute['required']) {
                    $nullValue = ' = null';
                }

                // Adds parameters and directives
                $constructorParams .= "\$${attribute['name']}$nullValue, ";
                $constructorDirectives .= "\n\t\t\$this->${attribute['name']} = $${attribute['name']};";

                $constructorParamsCount++;

            }

        }

        // Remove trailing characters from parameters
        if($constructorParamsCount > 0) {
            $constructorParams = substr($constructorParams, 0, strlen($constructorParams) - 2);
        }

        // Generate constructor and newEntity methods
        $constructor = "\n\tpublic function __construct($constructorParams) { $constructorDirectives\n\t}";
        $paramsValues = str_repeat('null, ', $constructorParamsCount);
        $newEntity = "\n\tpublic static function newEntity() { return new self(" . ($constructorParamsCount > 0 ? substr($paramsValues, 0, strlen($paramsValues) - 2) : $paramsValues) . "); }";

        // Generate getClass method
        $getClass = "\n\tpublic static function getClass() { return '$className'; }";

        // Generate template
        $template = "<?php\n\nnamespace app\models\graphql;\n\nuse app\helpers\GraphModelType;\n\nclass $className extends GraphModelType { $attributes\n$methods\n$constructor\n$newEntity\n$getClass\n}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/graphql/' . $className . '.php'), $template);

    }

    private static function typeConversion($type, $ignoreArray = false) {

        $isArray = false;

        // Check if is array
        if(self::isArray($type)) {
            $isArray = true;
            $type = substr($type, 1, strlen($type) - 2);
        }

        // Do conversion
        return str_replace(['Long', 'ID'], ['int', 'int'], $type) . ($isArray && !$ignoreArray ? '[]' : '');

    }

    private static function isArray($type) {
        return substr($type, 0, 1) == '[';
    }

    /**
     * Use this function to update graphql graph in database with provided graphql schema
     * @param $graphqlSchema String
     */
    private static function updateDatabaseGraph($graphqlSchema) {

        // Create a client to perform connection and set schema
        $client = ClientBuilder::create()
            ->addConnection('bolt', 'bolt://' . Yii::$app->params['db_username'] . ':' . Yii::$app->params['db_password'] . '@localhost:7687')
            ->build();

        // Load schema
        $schema = file_get_contents(Yii::getAlias("@app/models/$graphqlSchema"));

        // Deploy schema to database
        $query = "CALL graphql.idl('$schema')";

        $client->run($query);

    }

    /**
     * @deprecated
     * @param $queryClasses String[] Main classes on which execute query. Example: Movie, Person, Tweet...
     * @return Queries Queries object
     */
    public static function queries($queryClasses) {

        $queries = [];

        // Build query objects
        foreach ($queryClasses as $class) {
            $queries[$class] = self::query($class);
        }

        // Return queries
        return new Queries($queries);

    }

    /**
     * @param $queryClass String Main class on which execute query. Example: Movie, Person, Tweet...
     * @return Query
     */
    public static function query($queryClass, $callback) {
        // Build and return single query object
        return new Query($queryClass, $callback);
    }

    /**
     * @param $gql String GraphQL query
     * @return GraphModelType[]
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws GraphDatabaseAccessLayerException
     */
    public static function doQuery($gql) {
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

        $response = Json::decode($res->getBody()->getContents());

        // Check if has failed
        if(isset($response['errors'])) {
            throw new GraphDatabaseAccessLayerException($response['errors'][0]['message']);
        }

        // Result values
        return self::json2Object($response['data']);
    }

    private static function json2Object($json) {

        $result = [];

        foreach ($json as $modelName => $instances) {

            // Check if is single object or an array of objects
            if(array_values($instances) !== $instances) {
                $result = self::createModelFromJson($modelName, $instances);
            } else {
                foreach ($instances as $instance) {
                    $result[] = self::createModelFromJson($modelName, $instance);
                }
            }

        }

        return $result;

    }

    private static function createModelFromJson($modelName, $params) {

        // Create new model from class name
        $modelPath = "app\models\graphql\\$modelName";
        $model = $modelPath::newEntity();

        foreach ($params as $paramName => $paramValue) {

            // Method to call, if exists, to get types of attributes
            $getClassMethodName = 'get' . ucfirst($paramName) . 'Class';

            if (method_exists($model, $getClassMethodName)) {
                // Iterate through other graphql object
                $model->$paramName = self::json2Object([str_replace('app\models\graphql\\', '', $model->$getClassMethodName()) => $paramValue]);
            } else {
                // Set attribute value
                $model->$paramName = $paramValue;
            }

        }

        return $model;
    }

    /**
     * @deprecated
     * @param $gql
     */
    public static function mutation($gql) {
        // TODO
    }

}

// Queries class on which execute queries operations
class Queries {

    public function __construct($queries) {

    }

    public function executeQuery() {
        // TODO merge query in one single query request
    }

}

// Query class on which execute query operations
class Query {

    private $className;
    private $callback;

    public function __construct($className, $callback) {
        $this->className = $className;
        $this->callback = $callback;
    }

    public function buildQuery() {
        $query = Yii::$app->security->generateRandomString(10);
        // TODO build query here
        return $query;
    }

    /**
     * @throws GraphDatabaseAccessLayerException
     * @throws GuzzleHttp\Exception\GuzzleException
     * @return mixed
     */
    public function execute() {

        $className = "app\models\graphql\\$this->className";
        $callback = $this->callback;

        // First execute callback to discover which fields need to be fetched
        return $callback($className::newEntity());

        GraphDatabaseAccessLayer::doQuery(self::buildQuery());

        // Execute final callback
        //return $callback('test');

        //TODO
    }

}

abstract class GraphModelType {}

class GraphDatabaseAccessLayerException extends Exception {

    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
