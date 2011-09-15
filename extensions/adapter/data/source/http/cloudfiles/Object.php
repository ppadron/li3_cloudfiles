<?php

namespace li3_cloudfiles\extensions\adapter\data\source\http\cloudfiles;

use DateTime;
use DateTimeZone;

class Object extends \li3_cloudfiles\extensions\adapter\data\source\http\CloudFiles {
    
    /**
     * Map of actions to URI path and parameters.
     *
     * @var array 
     */
    protected $_sources = array(
        'read' => array(
            '/{:container}'         => array('container'),
            '/{:container}/{:name}' => array('container', 'name'),
        ),
        'create' => array(
            '/{:container}/{:name}' => array('container', 'name', 'content', 'type')
        ),
        'update' => array(
            '/{:container}/{:name}' => array('container', 'name', 'content', 'type', 'hash', 'lastModified', 'bytes')
        ),
        'delete' => array(
            '/{:container}/{:name}' => array('container', 'name')
        )
    );
    
    public function read($query, array $options = array()) {

        extract($query->export($this, array(
            'conditions', 'class', 'cascade'
        )));

        if (!$conditions) {
            $conditions = array();
        }
        
        $params   = array('data' => array());        
        $optional = array('marker', 'prefix', 'path', 'delimiter');

        foreach ($query->export($this, $optional) as $key => $value) {
            $params['data'][$key] = $value;
        }
        
        if (!isset($conditions['name'])) {
            $params['data']['format'] = 'json';
        }
        
        if ($query->limit()) {
            $params['data']['limit'] = $query->limit();
        }
        
        $model    = $query->model();
        $response = $this->_send(__FUNCTION__, 'GET', $conditions, $params);

        if ($response->status['code'] != 200) {
            return null;
        }

        if (isset($conditions['name'])) {

            $data = array(
                'name'         => $conditions['name'],
                'bytes'        => (integer) $response->headers('Content-Length'),
                'type'         => $response->headers('Content-Type'),
                'lastModified' => $this->_dateToTimestamp($response->headers('Last-Modified'), DateTime::RFC2822),
                'hash'         => $response->headers('Etag'),
                'content'      => $response->body[0]
            );
            
            return $this->item($query->model(), $data, array('class' => 'entity', 'exists' => true));
        }

        $result = array();

        foreach (json_decode($response->body[0]) as $file) {
            if (isset($cascade)) {
                $instance = $model::one($file->name, array('conditions' => array('container' => $conditions['container'])));
            } else {
                $instance = $this->_instance($this->_classes['entity'], array(
                    'model'  => $query->model(),
                    'exists' => true,
                    'data'   => array(
                        'name'         => $file->name,
                        'bytes'        => (integer) $file->bytes,
                        'hash'         => $file->hash,
                        'type'         => $file->content_type,
                        'lastModified' => $this->_dateToTimestamp($file->last_modified, "Y-m-d\TH:i:s\.u"),
                        'content'      => null
                    )
                ));
            }

            $result[] = $instance;
        }

        return $this->item($query->model(), $result, array('class' => 'set'));
    }
    
    /**
     * Updates an object. (Not implemented yet)
     * 
     * @throws QueryException
     * 
     * @param object $query Query object passed by the Model. 
     * @param array $params Additional parameters (eg. `headers`)
     * 
     * @return null
     */
    public function update($query, array $params = array()) {
        throw new QueryException('Update not implemented.');
    }
    
    /**
     * Creates a new object.
     * 
     * @param object $query Query object passed by the Model. 
     * @param array $params Additional parameters (eg. `headers`)
     * 
     * @return boolean Whether the object was created or not.
     */
    public function create($query, array $params = array()) {
        
        extract($query->export($this, array('data')));

        $model = $query->model();
        $data['data']['container'] = $model::meta('container');

        $response = $this->_send(__FUNCTION__, 'PUT', $data['data'], $params);

        $result = array(
            'name'         => $data['data']['name'],
            'bytes'        => mb_strlen($data['data']['content']),
            'hash'         => $response->headers('Etag'),
            'type'         => $data['data']['type'],
            'lastModified' => $this->_dateToTimestamp($response->headers('Last-Modified'), DateTime::RFC2822),
            'content'      => $data['data']['content']
        );
        
        $query->entity()->sync(null, $result);
        
        return true;
    }
    
    /**
     * Converts to timestamp a date string in a specific format.
     * 
     * @param string $date     Data string
     * @param string $format   Date format
     * @param string $timezone Timezone used in conversion (defaults to GMT)
     * 
     * @return int Timestamp in seconds since Unix epoch
     */
    protected function _dateToTimestamp($date, $format, $timezone = 'GMT') {
        return DateTime::createFromFormat(
            $format, $date, new DateTimeZone($timezone)
        )->getTimestamp();
    }
}

?>