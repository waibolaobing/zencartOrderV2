# Zen Cart 代码修改和重启指南

## 📂 代码目录挂载

已配置代码目录挂载：

- **本地目录**: `./zencart-code`
- **容器内目录**: `/var/www/html`
- **同步方式**: 实时同步，无需重启

## 🔄 重启服务的方式

### **1. 推荐：使用 Docker Compose**

```bash
# 重启所有服务
docker-compose restart

# 仅重启 Web 服务
docker-compose restart web

# 仅重启 MySQL 服务
docker-compose restart db

# 停止并重新创建容器
docker-compose up -d --force-recreate
```

### **2. 使用 Docker 原生命令**

```bash
# 重启 Web 容器
docker restart zencart-web

# 重启 MySQL 容器
docker restart zencart-mysql

# 重启所有相关容器
docker restart zencart-web zencart-mysql
```

### **3. 使用提供的脚本**

```bash
# 执行重启脚本
./restart-services.sh
```

## 🚀 无需重启的修改类型

### **✅ 不需要重启（实时生效）**

- **PHP 代码修改**: `.php` 文件修改后立即生效
- **HTML/CSS/JS**: 前端资源修改立即生效
- **模板文件**: Zen Cart 模板修改立即生效
- **语言包**: 语言文件修改立即生效

### **⚠️ 需要重启的修改类型**

- **Apache 配置**: `.htaccess` 或虚拟主机配置
- **PHP 配置**: `php.ini` 或 PHP 扩展
- **MySQL 配置**: 数据库配置文件
- **环境变量**: Docker 环境变量修改

## 📋 AI 修改代码工作流

```bash
# 1. AI 修改代码（自动保存到 ./zencart-code/）
# 2. 检查修改是否生效
curl http://localhost:8087

# 3. 如果需要重启（仅针对配置文件修改）
docker-compose restart web

# 4. 验证修改效果
echo "✅ 代码修改完成并生效！"
```

## 🔧 调试和监控

```bash
# 查看服务状态
docker-compose ps

# 查看 Web 服务日志
docker-compose logs -f web

# 查看 MySQL 服务日志
docker-compose logs -f db

# 进入 Web 容器调试
docker exec -it zencart-web bash

# 进入 MySQL 容器
docker exec -it zencart-mysql mysql -uroot -p123456
```

## 💡 性能优化建议

1. **开发模式**: 使用文件挂载便于调试
2. **生产模式**: 代码打包到镜像中提高性能
3. **热重载**: PHP 代码修改无需重启
4. **缓存清理**: 必要时清理 Zen Cart 缓存目录
