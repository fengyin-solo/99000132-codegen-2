<?php
/**
 * 测试沙箱：把项目复制到临时目录并注入 SQLite 版 config/database.php，
 * 让子进程在副本中运行，真实项目配置永远不被修改。
 */

function sqliteConfig(): string {
    return '<?php
define("DB_HOST", "localhost");
define("DB_USER", "root");
define("DB_PASS", "");
define("DB_NAME", "community_board");
define("DB_CHARSET", "utf8mb4");
class SandboxTestPDO extends PDO {
    #[\\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        $query = preg_replace("/\\s+FOR\\s+UPDATE/i", "", $query);
        $query = preg_replace("/\\bNOW\\(\\)/i", "datetime(\'now\')", $query);
        return parent::prepare($query, $options);
    }
}
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new SandboxTestPDO("sqlite:" . getenv("TEST_DB"), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
';
}

function provisionSandbox(): string {
    $projectRoot = dirname(__DIR__);
    $sandbox = sys_get_temp_dir() . '/claim_sandbox_' . getmypid();
    if (is_dir($sandbox)) {
        sandboxExec('rm -rf ' . escapeshellarg($sandbox));
    }
    mkdir($sandbox, 0755, true);

    // 复制运行所需目录/文件（排除 .git、tests、上传物）
    $entries = array_diff(scandir($projectRoot), ['.', '..', '.git', 'tests', 'uploads']);
    foreach ($entries as $entry) {
        sandboxExec('cp -R ' . escapeshellarg($projectRoot . '/' . $entry) . ' ' . escapeshellarg($sandbox . '/'));
    }
    @mkdir($sandbox . '/uploads', 0755, true);

    // 注入 SQLite 配置
    file_put_contents($sandbox . '/config/database.php', sqliteConfig());

    // 把测试工作进程放进沙箱（它们通过 TEST_SANDBOX 定位自身运行根目录）
    copy(__DIR__ . '/api_worker.php', $sandbox . '/api_worker.php');
    copy(__DIR__ . '/render_worker.php', $sandbox . '/render_worker.php');

    register_shutdown_function(function () use ($sandbox) {
        sandboxExec('rm -rf ' . escapeshellarg($sandbox));
    });

    return $sandbox;
}

function sandboxExec(string $cmd): void {
    $ret = 0;
    exec($cmd . ' 2>&1', $out, $ret);
    if ($ret !== 0) {
        fwrite(STDERR, "sandbox command failed ($ret): $cmd\n" . implode("\n", $out) . "\n");
    }
}
