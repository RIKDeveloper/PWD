<?php
/**
 * Получение данных от сервера по заданному url.
 *
 *
 *
 *
 * @param string $url url адрес с get параметрами (если нужно)
 * Пример передаваемого url:
 * 'http://ya.ru '
 * @param boolean $isJson нужно ли парсить данные как джейсон формат
 * @param boolean|array $curlOption параметры (username, password) для авторизации на сайте используя cURL (false если нет надабности использовать)
 * Пример передаваемых параметров:
 * ['username'=> 'admin', 'password'=>'12345678']
 * @return array декодированные данные
 */
function getData($url, $isJson = true, $curlOption = false)
{
    for ($i = 0; $i < 4; $i++) {
        if (!$curlOption) {
            $data = file_get_contents($url);
        } else {
            if (!empty($curlOption['username']) && !empty($curlOption['password'])) {
                $ch = curl_init();
                if (strtolower((substr($url, 0, 5)) == 'https')) { // если соединяемся с https
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                }
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (Windows; U; Windows NT 5.0; En; rv:1.8.0.2) Gecko/20070306 Firefox/1.0.0.4");
                curl_setopt($ch, CURLOPT_USERPWD, $curlOption['username'] . ':' . $curlOption['password']);
                $result = curl_exec($ch);

                curl_close($ch);

                if ($isJson) {
                    $result = json_decode($result, true);
                    if (is_array($result)) {
                        return $result;
                    }
                }

                return $result;
            }
        }

        if ($isJson) {
            $data = json_decode($data, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return $data;
    }
    throw new Exception('Не могу получить данные от сайта: ' . $url . PHP_EOL);

}

class ParseWriteData
{
    private $logs = [];
    private $nameLogDirectory;
    private $nameLogFile;

    //settings -> nameLogFile, option for connect om postgres,

    public function __construct($settings = false)
    {
        $keys = ['logging', 'nameLogDirectory', 'NLD', 'nameLogFile', 'NLF'];
        $replaceEmptyValuesKeysTrue = ['logging'];
        foreach ($keys as $key) {
            if (empty($settings[$key])) {
                $settings[$key] = in_array($key, $replaceEmptyValuesKeysTrue);
            }
        }

        if ($settings['logging'] === true) {
            $this->logs['time_start'] = date('d.m.y H:i:s');

            if (is_string($settings['nameLogDirectory']) || is_numeric($settings['nameLogDirectory'])) {
                $this->nameLogDirectory = $settings['nameLogDirectory'];
            } else {
                $this->nameLogDirectory = 'logs/' . '__' . date('ymd_Hi') . DIRECTORY_SEPARATOR;
            }

            if (is_string($settings['nameLogFile']) || is_numeric($settings['nameLogFile'])){
                $this->nameLogFile = $settings['nameLogFile'];
                if(strpos($this->nameLogFile, '.json', -5) === false){
                    $this->nameLogFile .= '.json';
                }
            } else{
                $this->nameLogFile = date('dmy__H-i') . crc32(microtime(true)) . '.json';
            }

            mkdir($this->nameLogDirectory);
        } else {

        }
    }
}

function formatData($options, $columns)
{
    $res = [
        'columns' => '',
        'values' => ''
    ];

    if (empty($options['__nums']))
        $options['__nums'] = [];

    foreach ($columns as $id => $name) {
        if (strlen($res['values']) != 0) {
            $res['values'] .= ', ';
            $res['columns'] .= ', ';
        }

        if (is_numeric($id)) {
            $res['columns'] .= $name;
        } else {
            $res['columns'] .= $id;
        }

        if (!empty($options[$name]) || !empty($options['__get'])) {
            if (!is_array($options[$name])) {
                if (in_array($name, $options['__nums'])) {
                    $res['values'] .= $options[$name];
                } else {
                    $res['values'] .= "'" . trim(str_replace("'", '"', $options[$name])) . "'";
                }
            } else {
                $res['values'] .= 'null';
            }
        } else {
            $res['values'] .= 'null';
        }
    }

    return $res;
}

function mergerObj($data, $option, $param)
{
    if (!is_array($data))
        $data = (array)$data;

    if (empty($option))
        return $data;

    $res = [];

    //array_change_key_case приводит ключи к ниижнему регистру

    if (empty($option['prefix']))
        $option['prefix'] = '';

    if (!empty($option['main']) && is_array($option['main'])) {
        foreach ($option['main'] as $item) {
            $res[$option['prefix'] . $item] = $data[$item];
        }
    } elseif (!empty($option['main'])) {
        foreach ($data as $id => $value) {
            if (!is_array($value) && !is_object($value))
                $res[$option['prefix'] . $id] = $value;
        }
    }

    if (!empty($option['second']) && !empty($option['second']['__all'])) {
        foreach ($data as $id => $value) {
            if (is_object($data[$id]) || is_array($data[$id])) {
                $res = array_merge(mergerObj((array)$data[$id], ['main' => 'all', 'prefix' => str_replace('%parent%', $id, $option['second']['__prefix'])]), $res);
            }
        }
    } elseif (!empty($option['second'])) {
        foreach ($option['second'] as $id => $item) {
            if (is_array($data[$id]) || is_object($data[$id]))
                $res = array_merge(mergerObj((array)$data[$id], $item), $res);
        }
    }

    if ($param !== false) {
        return array_merge($res, $param);
    }

    return $res;
}

function createInsert($data, $option, $table, $columns, $param = false, $checkOption = false)
{
    if (empty($data))
        return false;
    $insertFull = ['columns' => '', 'values' => ''];

    foreach ($data as $id => $item) {
        $tempData = mergerObj($item, $option, $param);
        if ($checkOption !== false)
            if (!in_array($tempData[$checkOption['name']], $checkOption['array']))
                $checkOption['array'][] = $tempData[$checkOption['name']];

        $insert = formatData($tempData, $columns);
        $insertFull['columns'] = $insert['columns'];
        $insertFull['values'] .= '(' . $insert['values'] . ')' . ($id != count($data) - 1 ? ', ' : '');
    }

    if (strrpos($insertFull['values'], ', ', -2) == (strlen($insertFull['values']) - 2)) {
        $insertFull['values'] = substr($insertFull['values'], 0, strlen($insertFull['values']) - 2);
    }

    if (strlen($insertFull['values']) > 2) {
        return 'INSERT INTO ' . $table . '(' . $insertFull['columns'] . ') VALUES ' . $insertFull['values'];
    }

    return false;
}

function fillColumnTable($table, $dbconnect)
{
    $res = [];
    $data = pg_fetch_all(pg_query($dbconnect, "select column_name,data_type from information_schema.columns where table_name = '" . $table . "'"));
    foreach ($data as $item) {
        $res[] = $item['column_name'];
    }

    return $res;
}

function updateActionInFileOption($tableList, $table, $filepath = 'option.json')
{
    $tempTableList = json_decode(file_get_contents($filepath), true);
    $tempTableList[$table]['action'] = $tableList[$table]['action'];
    file_put_contents('option.json', json_encode($tempTableList));
}

function getColumn($option, $table, $dbconnect)
{
    if (empty($option['column'])) {
        return fillColumnTable($table, $dbconnect);
    } elseif (!empty($option['column']['%notAll%'])) {
        if ($option['column']['%notAll%'] === true) {
            $columnsDB = fillColumnTable($table, $dbconnect);
            unset($option['column']['%notAll%']);
            foreach ($option['column'] as $name => $column) {
                if (in_array($name, $columnsDB) !== false) {
                    array_splice($columnsDB, array_search($name, $columnsDB), 1);
                }
            }
            return array_merge($columnsDB, $option['column']);
        }
    } else {
        return $option['column'];
    }
}

function getParam($name, $values, $isArr = false)
{
    $res = [];
    if (is_array($values)) {
        foreach ($values as $value) {
            $res[] = $isArr ? [$name => $value] : ($name . '=' . $value);
        }
    } else {
        $posReturn = strrpos($values, '%return');
        if ($posReturn !== false) {
            $value = substr($values, 0, $posReturn) .
                eval(substr($values, $posReturn + 1, strrpos($values, '%', $posReturn + 1) - $posReturn - 1)) .
                substr($values, strrpos($values, '%', $posReturn + 1) + 1);
            return $isArr ? array([$name => $value]) : [$name . '=' . $value];
        }
        return $isArr ? array([$name => $values]) : [$name . '=' . $values];
    }
    return $res;
}

function paramsGetRequest($param, $isArr = false)
{
    $getParams = [];

    foreach ($param as $name => $value) {
        if ($getParams == []) {
            $getParams[0] = getParam($name, $value, $isArr);
        } else {
            $getParams[1] = getParam($name, $value, $isArr);
            foreach ($getParams[0] as $item) {
                foreach ($getParams[1] as $item1) {
                    $getParams[2][] = $isArr ? array_merge($item, $item1) : $item . '&' . $item1;
                }
            }

            array_shift($getParams);
            array_shift($getParams);
        }
    }

    return $getParams[0];
}

function createOutputInfo($num, $count, $table, $step = 25)
{
    $load = $table . ':  [';
    if ($count / $step < 1)
        $step = $count;
    $length = floor($num / floor($count / $step));
    for ($i = 0; $i < $step; $i++) {
        if ($length - $i > 0)
            $load .= '#';
        else
            $load .= '.';
    }
    $load .= ']';
    return $load . PHP_EOL;
}

function exceptionHandler($code, $msg, $file, $line)
{
    global $time, $tableDB, $table, $logs, $nameLogFile;
    $logs[$time][$table]['error'][] = $msg . PHP_EOL . date('d.m.y H:i:s');
    file_put_contents($nameLogFile, json_encode($logs, JSON_UNESCAPED_UNICODE));
    echo $msg . ' ' . $line . PHP_EOL;
}



