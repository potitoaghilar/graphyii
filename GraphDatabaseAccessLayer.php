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

        $modelsPath = Yii::getAlias('@' . self::getModelsPath(false, true));

        // Get schema data from
        $schema = file_get_contents($modelsPath . $graphqlSchema);

        // Normalize datas and extract types
        $types = self::extractTypes($schema);

        // Create path if not exists
        FileHelper::createDirectory($modelsPath . 'graphql', $mode = 0775, $recursive = true);

        // Create model classes
        foreach ($types as $type) {
            self::createModel($type);
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
                        'visibility' => 'protected', // TODO this can be extended in future
                    ];

                }

                // Sort attributes by required fields
                usort($attributes, function ($a, $b) {
                    return $b['required'] <=> $a['required'];
                });

                // Create new type
                $types[] = [
                    'className' => $formattedParts[0],
                    'attributes' => $attributes,
                ];

            }

        }

        return $types;
    }

    /*private static function extractTypeNames($types) {
        $allTypeNames = [];

        foreach ($types as $type) {
            $allTypeNames[] = $type['className'];
        }

        return $allTypeNames;
    }*/

    private static function createModel($type) {

        // Get className
        $className = $type['className'];

        // Get attributes and generate methods
        $attributes = '';
        $methods = '';
        $constructorParams = '';
        $constructorDirectives = '';
        $constructorParamsCount = 0;

        foreach ($type['attributes'] as $attribute) {

            // Check if type is standard or GraphModelType
            $isGraphModelType = self::isGraphModelType($attribute['type']);

            // Generate documentation and attribute
            $required = '';
            if($attribute['required']) {
                $required = "\n\t * @required";
            }
            $documentation = "\t/**\n\t * @var ${attribute['type']}" . ($attribute['isArray'] ? '[]' : '') . "$required\n\t */";
            $attributes .= "\n\n$documentation\n\t${attribute['visibility']} $${attribute['name']};";


            // Generate getter and setter methods for all attributes
            /*$graphModelTypeExtension = '';
            if($isGraphModelType) {
                $graphModelTypeExtension = "\$this->isQuery && \$this->${attribute['name']} == null ? [new ${attribute['type']}()] : ";
            }*/
            $methods .= "\n\n\tpublic function ${attribute['name']}() {\n\t\tparent::requestAttribute('${attribute['name']}', '${attribute['type']}');\n\t\treturn \$this->${attribute['name']};\n\t}"; // Getter
            $methods .= "\n\n\tpublic function set" . ucfirst($attribute['name']) . "($${attribute['name']}) {\n\t\t\$this->${attribute['name']} = $${attribute['name']};\n\t}"; // Setter

            // Generate methods for defined types
            if($isGraphModelType) {
                $methods .= "\n\n\tpublic function get" . ucfirst($attribute['name']) . "Class() { return ${attribute['type']}::getClass(); }";
            }

            // Check if is a standard type
            if(!$isGraphModelType) {

                /*// Check if required
                $nullValue = '';
                if(!$attribute['required']) {
                    $nullValue = ' = null';
                }*/

                // Adds parameters and directives to constructor
                $constructorParams .= "\$${attribute['name']} = null, ";
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
        //$paramsValues = str_repeat('null, ', $constructorParamsCount);
        //$newEntity = "\n\tpublic static function newEntity() { return new self(" . ($constructorParamsCount > 0 ? substr($paramsValues, 0, strlen($paramsValues) - 2) : $paramsValues) . "); }";

        // Generate getClass method
        $getClass = "\n\tpublic static function getClass() { return '$className'; }";

        // Generate overridden query method
        $methods .= "\n\n\tpublic static function query(\$attributes, \$callback) {\n\t\treturn parent::executeQuery(self::getClass(), \$callback);\n\t}";

        // Generate user query attributes
        $queryAttributes = self::generateQueryAttributes($type['attributes']);

        // Generate template
        $template = "<?php\n\nnamespace " . self::getModelsPath(true, false, false) . ";\n\nuse app\helpers\GraphModelType;\n\nclass $className extends GraphModelType { $attributes\n$methods\n$constructor\n$getClass\n}\n\nclass ${className}QueryAttributes {\n$queryAttributes}";

        // Save model
        file_put_contents(Yii::getAlias('@app/models/graphql/' . $className . '.php'), $template);

    }

    private static function generateQueryAttributes($attributes) {

        $queryAttributes = '';

        foreach ($attributes as $attribute) {
            $queryAttributes .= "\tconst ${attribute['name']} = '${attribute['name']}';\n";
        }

        return $queryAttributes;

    }

    public static function isGraphModelType($type) {
        return is_subclass_of(self::getModelsPath(true) . $type, 'app\helpers\GraphModelType');
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
     * @param $callback
     * @return Query
     */
    public static function query($callback) {
        // Build and return single query object
        return new Query($callback);
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
        $modelName = self::getModelsPath(true) . $modelName;
        $model = new $modelName();

        foreach ($params as $paramName => $paramValue) {

            // Method to call, if exists, to get types of attributes
            $getClassMethodName = 'get' . ucfirst($paramName) . 'Class';

            // Define setter method
            $setter = "set$paramName";

            if (method_exists($model, $getClassMethodName)) {
                // Iterate through other graphql object
                $model->$setter(self::json2Object([str_replace(self::getModelsPath(true), '', $model->$getClassMethodName()) => $paramValue]));
            } else {
                // Set attribute value
                $model->$setter($paramValue);
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

    public static function getModelsPath($isGraphQLModel, $forwardSlash = false, $finalSlash = true) {

        $absPath = '';

        if($isGraphQLModel) {
            $absPath = !$forwardSlash ? 'app\models\graphql\\' : 'app/models/graphql/';
        } else {
            $absPath = !$forwardSlash ? 'app\models\\' : 'app/models/';
        }

        // Remove final slash if required
        if(!$finalSlash) {
            $absPath = substr($absPath, 0, strlen($absPath) - 1);
        }

        return $absPath;
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

    private $callback;

    public function __construct($callback) {
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

        $callback = $this->callback;

        // First execute callback to discover which fields need to be fetched
        return $callback();

        //GraphDatabaseAccessLayer::doQuery(self::buildQuery());

        // Execute final callback
        //return $callback('test');

        //TODO
    }

}

class GraphDatabaseAccessLayerException extends Exception {

    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function __toString() {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
