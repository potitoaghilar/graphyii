<?php

namespace app\helpers;


abstract class GraphModelType {

    protected $isQuery = false;
    protected $isMutation = false;

    // Requested attributes for a new query
    public $requestedAttributes = [];

    // Adds required attribute to request list
    public function requestAttribute($attributeName, $attributeType) {
        // If is a query object add attribute request
        if($this->isQuery) {

            // Check if attribute is a custom type
            if(GraphDatabaseAccessLayer::isGraphModelType($attributeType)) {

                // Assign custom attribute to continue tree analyzing
                if($this->$attributeName == null) {
                    $attributeModelName = GraphDatabaseAccessLayer::getModelsPath(true) . $attributeType;
                    $attributeModel = new $attributeModelName();
                    $attributeModel->isQuery = true;
                    $this->$attributeName = [$attributeModel];

                    // Attach child requested attributes to root
                    $this->requestedAttributes[$attributeName] = [
                        'type' => $attributeType,
                        'value' => &$attributeModel->requestedAttributes,
                    ];

                }

            } else {

                $this->requestedAttributes[$attributeName] = [
                    'type' => $attributeType,
                    'value' => $attributeName,
                ];

            }

        }
    }

    private static function buildQuery($className, $requestedAttributes, $isRoot = false) {
        $query = '';

        // Build requested attributes
        foreach ($requestedAttributes as $key => $attribute) {
            if(GraphDatabaseAccessLayer::isGraphModelType($attribute['type'])) {
                $query .= self::buildQuery($key, $requestedAttributes[$key]['value']) . ',';
            } else {
                $query .= $key . ',';
            }
        }

        // Encapsulate
        $query = "$className{ $query }";

        // Return extra parenthesis only if is root
        return $isRoot ? "{ $query }" : $query;
    }

    /**
     * @param $className
     * @param $callback
     * @return mixed
     * @throws GraphDatabaseAccessLayerException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected static function executeQuery($className, $callback) {

        // Create new empty entity for provided class and set it as query
        $modelName = GraphDatabaseAccessLayer::getModelsPath(true) . $className;
        $model = new $modelName();
        $model->isQuery = true;

        // First execute callback to discover which fields need to be fetched
        $callback([$model]);

        $queryResult = GraphDatabaseAccessLayer::doQuery(self::buildQuery($className, $model->requestedAttributes, true));

        // Execute final callback
        return $callback($queryResult);
    }

}