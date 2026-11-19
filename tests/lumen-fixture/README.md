# Lumen + MariaDB 真实 HTTP 验收

本目录提供独立 Composer 项目的 Lumen 10 / PHP 8.2 / Workerman 5 / MariaDB 11.4 验收配置，不改变仓库根依赖。配置目标不等于本机已执行结果，实测版本与结果见 [验证报告](../../docs/verification.md)。

从仓库根目录运行：

```bash
docker compose -p workerman-lumen-acceptance -f tests/docker-compose.acceptance.yml up -d --build --wait mariadb app
```

```bash
docker compose -p workerman-lumen-acceptance -f tests/docker-compose.acceptance.yml exec -T app php acceptance.php
```

```bash
docker compose -p workerman-lumen-acceptance -f tests/docker-compose.acceptance.yml exec -T app php /app/tests/acceptance/run.php
```

```bash
docker compose -p workerman-lumen-acceptance -f tests/docker-compose.acceptance.yml exec -T app php /app/tests/acceptance/protocol.php
```

```bash
docker compose -p workerman-lumen-acceptance -f tests/docker-compose.acceptance.yml down -v --remove-orphans
```

验收脚本用真实 HTTP socket 并发写入 MariaDB，检查每个请求标识、响应与数据库的对应关系、数据库行数和原子计数；随后验证未提交事务在请求结束时回滚且后续请求仍可访问数据库。默认启动 4 个 Linux Worker。测试端口仅绑定本机；数据库凭据仅供本地测试使用，不能用于生产环境。
