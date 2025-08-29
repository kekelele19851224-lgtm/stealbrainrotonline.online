<?php
/**
 * 简单的代理脚本来绕过X-Frame-Options限制
 * 用于解决GameFlare iframe嵌入问题
 */

// 设置CORS头
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// 获取目标URL
$targetUrl = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($targetUrl)) {
    http_response_code(400);
    echo json_encode(['error' => '缺少目标URL参数']);
    exit;
}

// 验证URL格式
if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => '无效的URL格式']);
    exit;
}

// 只允许访问特定域名以提高安全性
$allowedDomains = [
    'gameflare.com',
    'www.gameflare.com',
    'playhop.com',
    'www.playhop.com'
];

$urlHost = parse_url($targetUrl, PHP_URL_HOST);
if (!in_array($urlHost, $allowedDomains)) {
    http_response_code(403);
    echo json_encode(['error' => '不允许访问的域名']);
    exit;
}

try {
    // 初始化cURL
    $ch = curl_init();
    
    // 设置cURL选项
    curl_setopt_array($ch, [
        CURLOPT_URL => $targetUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_REFERER => 'https://gameflare.com/',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache'
        ]
    ]);
    
    // 执行请求
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    if (curl_error($ch)) {
        throw new Exception('cURL错误: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    // 分离响应头和内容
    $headers = substr($response, 0, $headerSize);
    $content = substr($response, $headerSize);
    
    // 设置适当的Content-Type
    if ($contentType) {
        header('Content-Type: ' . $contentType);
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }
    
    // 移除可能导致问题的响应头
    $headersToRemove = [
        'x-frame-options',
        'content-security-policy',
        'x-content-type-options'
    ];
    
    $responseHeaders = explode("\r\n", $headers);
    foreach ($responseHeaders as $header) {
        $header = trim($header);
        if (empty($header) || strpos($header, 'HTTP/') === 0) {
            continue;
        }
        
        $headerName = strtolower(explode(':', $header)[0]);
        if (!in_array($headerName, $headersToRemove)) {
            header($header);
        }
    }
    
    // 对HTML内容进行处理
    if (strpos($contentType, 'text/html') !== false) {
        // 修改HTML内容以确保在iframe中正常工作
        $content = modifyHtmlContent($content, $targetUrl);
    }
    
    // 设置HTTP状态码
    http_response_code($httpCode);
    
    // 输出内容
    echo $content;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '代理请求失败: ' . $e->getMessage()]);
}

/**
 * 修改HTML内容以确保在iframe中正常工作
 */
function modifyHtmlContent($html, $baseUrl) {
    $baseHost = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
    
    // 添加base标签以确保相对URL正确解析
    $baseTag = '<base href="' . htmlspecialchars($baseHost) . '/" target="_parent">';
    $html = preg_replace('/<head\b[^>]*>/i', '$0' . $baseTag, $html, 1);
    
    // 移除可能阻止iframe嵌入的JavaScript代码
    $patterns = [
        '/if\s*\(\s*window\s*!=\s*window\.top\s*\)[^}]*}/i',
        '/if\s*\(\s*top\s*!=\s*self\s*\)[^}]*}/i',
        '/if\s*\(\s*window\.top\s*!=\s*window\s*\)[^}]*}/i',
        '/top\.location\s*=\s*[^;]+;/i',
        '/window\.top\.location\s*=\s*[^;]+;/i'
    ];
    
    foreach ($patterns as $pattern) {
        $html = preg_replace($pattern, '', $html);
    }
    
    // 注入一些有用的JavaScript代码
    $injectedScript = '
    <script>
    // 防止页面跳转出iframe
    if (window !== window.top) {
        window.addEventListener("beforeunload", function(e) {
            return null;
        });
        
        // 向父窗口发送消息
        try {
            window.parent.postMessage({
                type: "gameLoaded",
                url: window.location.href
            }, "*");
        } catch(e) {}
    }
    
    // 修复可能的相对URL问题
    document.addEventListener("DOMContentLoaded", function() {
        var links = document.querySelectorAll("a[href^=\'/\']");
        links.forEach(function(link) {
            link.href = "' . $baseHost . '" + link.getAttribute("href");
        });
    });
    </script>';
    
    $html = str_replace('</body>', $injectedScript . '</body>', $html);
    
    return $html;
}

/**
 * 记录访问日志（可选）
 */
function logAccess($url, $success = true) {
    $logFile = 'proxy_access.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $status = $success ? 'SUCCESS' : 'FAILED';
    
    $logEntry = "[$timestamp] $status - IP: $ip - URL: $url - UA: $userAgent" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// 可选：记录访问
if (defined('ENABLE_LOGGING') && ENABLE_LOGGING) {
    logAccess($targetUrl, $httpCode < 400);
}
?>