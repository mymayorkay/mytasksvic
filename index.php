<?php

class Core
{

    public function base_dir()
    {
        $included_files = get_included_files();
        $path_info = pathinfo($included_files[0]);
        return $path_info['dirname'];
    }

    public function url_scheme()
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https' : strtolower(explode('/', $_SERVER['SERVER_PROTOCOL'])[0]);
    }

    public function is_http_secure()
    {
        $protocol = $this->url_scheme();
        if (preg_match('/^https$/i', $protocol)) {
            return true;
        }
        return false;
    }

    public function url_path()
    {
        $full_url_path = strtolower(urldecode($this->url_full_path()));
        $url_path = strtolower(urldecode($this->url_base_path()));
        $url_path = trim(preg_replace('/' . preg_quote($url_path, '/') . '/i', '', $full_url_path), '/');
        return $url_path;
    }

    public function url_base_path()
    {
        $base_path = $this->base_dir();
        $root_path = preg_replace('/\\\|\//', DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT']);
        $path = str_replace($root_path, '', $base_path);
        return trim(preg_replace('/\\\|\//', '/', $path), '/');
    }

    public function url_full_path()
    {
        return trim(preg_replace('/\?.*?$/', '', $_SERVER['REQUEST_URI']), '/');
    }

    public function start()
    {
        $routeId = '';
        if (substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
            ob_start('ob_gzhandler');
        } else {
            ob_start();
        }
        $routes = $this->routes();
        $page_url = $this->url_path();
        if (empty($page_url)) {
            return $this->routeResponse(['status' => false, 'data' => [], 'message' => 'Forbidden'], 404);
        } else {
            foreach ($routes as $id => $route) {
                if ($route['url'] == $page_url) {
                    $routeId = $id;
                }
            }
        }
        if (!$routeId) {
            return $this->routeResponse(['status' => false, 'data' => [], 'message' => 'Forbidden'], 404);
        }
        $route = $routes[$routeId];
        $routeMethod = $route['method'];
        if ($routeMethod != strtolower($_SERVER['REQUEST_METHOD'])) {
            return $this->routeResponse(['status' => false, 'data' => [], 'message' => 'Forbidden'], 404);
        }
        $controller = explode('@', $route['action']);

        if (count($controller) === 2 && class_exists($controller[0])) {
            $className = $controller[0];
            $methodName = $controller[1];
            $classInstance = new $className();
            if (method_exists($classInstance, $methodName)) {
                return $classInstance->$methodName();
            } else {
                throw new Exception("Method {$methodName} does not exist in class {$className}");
            }
        } else {
            throw new Exception("Invalid controller or method in route action.");
        }

    }

    public function routeResponse($data, $code = 200)
    {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($data, JSON_PRETTY_PRINT);
    }

    public function routes()
    {
        return [
            'exams' => [
                'url' => 'list',
                'action' => 'Exam@index',
                'method' => 'post'
            ]
        ];
    }
}

class Exam extends Core
{
    public function index()
    {
        $requestInput = file_get_contents('php://input');
        $requestInput = json_decode($requestInput, true);
        $filters = isset($requestInput['filters']) ? $requestInput['filters'] : [];

        $examsJsonData = file_get_contents('TechTestJson.json');
        $examsArray = json_decode($examsJsonData, true);

        $exams = $examsArray['Exams'];

        if (!empty($filters)) {
            $exams = array_filter($exams, function ($exam) use ($filters) {
                $matches = true;

                if (!empty($filters['location_name']) && strcasecmp($exam['LocationName'], $filters['location_name']) !== 0) {
                    $matches = false;
                }
                if (!empty($filters['longitude_min']) && !empty($filters['longitude_max'])) {
                    if ($exam['Longitude'] < $filters['longitude_min'] || $exam['Longitude'] > $filters['longitude_max']) {
                        $matches = false;
                    }
                }
                if (!empty($filters['latitude_min']) && !empty($filters['latitude_max'])) {
                    if ($exam['Latitude'] < $filters['latitude_min'] || $exam['Latitude'] > $filters['latitude_max']) {
                        $matches = false;
                    }
                }
                if (!empty($filters['title']) && stripos($exam['Title'], $filters['title']) === false) {
                    $matches = false;
                }
				                if (!empty($filters['candidate_name']) && stripos($exam['CandidateName'], $filters['candidate_name']) === false) {
                    $matches = false;
                }
				
                if (!empty($filters['description']) && stripos($exam['Description'], $filters['description']) === false) {
                    $matches = false;
                }

                return $matches;
            });
        }
        $sortBy = isset($filters['sort_by']) ? $filters['sort_by'] : 'Date';
        $sortOrder = isset($filters['sort_order']) ? $filters['sort_order'] : 'asc';
        usort($exams, function ($a, $b) use ($sortBy, $sortOrder) {
            if ($sortBy === 'Date') {
                $dateA = DateTime::createFromFormat('d/m/Y H:i:s', $a['Date']);
                $dateB = DateTime::createFromFormat('d/m/Y H:i:s', $b['Date']);
                return $sortOrder === 'asc' ? $dateA <=> $dateB : $dateB <=> $dateA;
            }

            return $sortOrder === 'asc' ? strnatcmp($a[$sortBy], $b[$sortBy]) : strnatcmp($b[$sortBy], $a[$sortBy]);
        });

        $exams = array_values($exams);
        return $this->routeResponse(['status' => true, 'data' => $exams, 'message' => 'success'], 200);
    }
}

$system = new Core();
$system->start();