<?php
namespace ESearch;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Bolt\StorageEvents;
use Bolt\StorageEvent;

use Elasticsearch\Client;

class Extension extends \Bolt\BaseExtension
{

    /**
     * @Client
     */
    private $client;

    function info()
    {

        $data = array(
            'name' => "Elasticsearch",
            'description' => "Elasticsearch integration for Bolt CMS",
            'keywords' => "elasticsearch, search, index",
            'author' => "Olivier Revollat",
            'link' => "https://github.com/revollat",
            'version' => "0.1",
            'required_bolt_version' => "1.3",
            'highest_bolt_version' => "1.6",
            'type' => "Search",
            'first_releasedate' => "2014-08-27",
            'latest_releasedate' => "2014-08-27",
        );

        return $data;

    }

    function initialize()
    {
        $path = $this->app['config']->get('general/branding/path') . '/elasticsearch_index_content';
        $this->app->match($path, array($this, 'indexcontent'));
        $this->addMenuOption('Elasticsearch / Index Content', 'elasticsearch_index_content', 'icon-list', 'extensions');

        $client_params = $this->config['client_params'];
        $this->client = new Client($client_params);

        $this->app['dispatcher']->addListener(StorageEvents::POST_SAVE, array($this, 'postSave'));
        $this->app['dispatcher']->addListener(StorageEvents::POST_DELETE, array($this, 'postDelete'));


    }

    function postDelete(StorageEvent $event)
    {

        //$params = array();
        $params['index'] = $this->config['indexname'];
        $params['type'] = $event->getContentType();
        $params['id'] = $event->getId();

        $exist = $this->client->exists($params);
        if($exist){
            $ret = $this->client->delete($params);
            $this->app['log']->add("DELETE " . $params['type'] . ", ID = " . $params['id']  , 2, false, 'elasticsearch');
        }

    }

    function postSave(StorageEvent $event)
    {

        $params = array();
        $params['index'] = $this->config['indexname'];
        $params['type'] = $event->getContentType();
        $params['id'] = $event->getId();
        $type = $params['type'];

        $exist = $this->client->exists($params);

        $params['body']  = array();

        if($exist){
            $log_msg = "UPDATE";
            $data = array();
            foreach($this->config[$type] as $field => $field_param)
            {
                $content = $event->getContent();
                $data[$field] = $content[$field]->__toString();
            }
            $params['body']['doc'] = $data;
            $ret = $this->client->update($params);
        }else{
            $log_msg = "INSERT";
            foreach($this->config[$type] as $field => $field_param)
            {
                $content = $event->getContent();
                $params['body'][$field] = $content[$field]->__toString();
            }
            $ret = $this->client->index($params);
        }

        $this->app['log']->add($log_msg . $params['type'] . ", ID = " . $params['id']  , 2, false, 'elasticsearch');


    }

    public function indexcontent()
    {
        $this->requireUserPermission('extensions');

        $output = "";

        $indexname = $this->config['indexname'];
        $indexParams['index'] = $indexname;

        // If index exists delete it ...
        if($this->client->indices()->exists($indexParams)){
            $this->client->indices()->delete($indexParams);
            $output .= "<p>Successfully deleted <code>" . $indexname . "</code> index.</p>";
        }
        // ... and recreate it.
        $indexParams['body'] = $this->config['indexsetting'];
        $result = $this->client->indices()->create($indexParams);

        if($result['acknowledged']){
            $output .= "<p>Successfully created <code>" . $indexname . "</code> index.</p>";
        }else{
            $output .= "<p>Error while creating <code>" . $indexname . "</code> index.</p>";
            $output .= \util::var_dump($result, true);
        }

        foreach ($this->config['content_types'] as $type ) {

            $indexParams['type']  = $type;
            $properties = array();
            $indexParams['body'] = array();

            foreach($this->config[$type] as $field => $field_param){
                $properties[$field] = $field_param['mapping'];
            }

            $mapping = array(
                '_source' => array(
                    'enabled' => true
                ),
                'properties' => $properties
            );

            $indexParams['body'][$type] = $mapping;

            $output .= "<p>Mapping for <code>$type</code></p>";
            $output .= \util::var_dump($indexParams, true);
            $this->client->indices()->putMapping($indexParams);

        }

//        $indexParams['type']  = 'soldats';
//        $mapping = array(
//            '_source' => array(
//                'enabled' => true
//            ),
//            'properties' => array(
//                'nom' => array(
//                    'type' => 'string',
//                    'analyzer' => 'standard'
//                ),
//                'prenom' => array(
//                    'type' => 'string',
//                    'analyzer' => 'standard'
//                )
//            )
//        );
//        $indexParams['body']['soldats'] = $mapping;
//        $output .= \util::var_dump($mapping, true);
//        $this->client->indices()->putMapping($indexParams);



        foreach ($this->config['content_types'] as $type ) {

            $contenttype = $this->app['storage']->getContentType($type);

            $contenttypeslug = $contenttype['slug'];
            $output .= "<p>Indexing <code>" . $contenttypeslug . "</code> ...</p>";
            $content = $this->app['storage']->getContent($contenttype['slug']);

            $params = array();
            $params['index'] = $indexname;
            $params['type']  = $contenttypeslug;

            foreach($content as $record){


//               $data = array();
//                foreach($this->config[$contenttypeslug] as $field => $field_param)
//                {
//                    $data[$field] = $record[$field]->__toString();
//                }
//
//                $params['body'][] = array(
//                    'index' => array(
//                        '_id' => $record['id']
//                    )
//                );
//
//                $params['body'][] = array(
//                    $contenttypeslug => $data
//                );

                $params['id']    = $record['id'];
                $params['body']  = array();
                foreach($this->config[$contenttypeslug] as $field => $field_param)
                {
                    $params['body'][$field] = $record[$field]->__toString();
                    //$output .= \util::var_dump($record[$field], true);

                }
                $ret = $this->client->index($params);

                //$output .= \util::var_dump($ret, true);

            }

//            $responses = $this->client->bulk($params);
//
//            //$output .= \util::var_dump($responses, true);
//
//            if(!$responses['errors']){
//                $output .= "<p>Successfully indexed " . count($responses['items']) . " <code>" . $contenttypeslug . "</code> in ". $responses['took'] ." ms</p>";
//            }else{
//                $output .= "<p>Error</p>";
//                $output .= \util::var_dump($responses, true);
//            }

        }

        return $this->app['render']->render('base.twig', array(
            'title' => "Elasticsearch / Index Content",
            'content' => $output
        ));

    }


}

