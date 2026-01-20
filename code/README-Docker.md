# Zen Cart Docker 环境

这是一个完全独立的 Zen Cart Docker 环境，所有代码和数据都打包在镜像中，无需外部挂载。

## 📦 镜像说明

- **zencart-web**: 包含 PHP 7.4 + Apache + 完整 Zen Cart 代码
- **zencart-mysql**: 包含 MySQL 8.0 + 初始化数据库配置

## 🚀 快速开始

### 1. 构建镜像

```bash
./build-images.sh
```

### 2. 启动环境

```bash
./start.sh
```

### 3. 访问应用

- **Zen Cart**: http://localhost:8087
- **phpMyAdmin**: http://localhost:8081

## 📋 数据库信息

- **主机**: localhost:3306 (主机访问) / db (容器内访问)
- **数据库**: zencart
- **用户**: root / zencart
- **密码**: 123456

## 🔧 常用命令

### 查看容器状态

```bash
docker-compose ps
```

### 查看日志

```bash
docker-compose logs -f
```

### 停止环境

```bash
docker-compose down
```

### 重启环境

```bash
docker-compose restart
```

## 💾 使用 Docker Commit

由于所有代码都在镜像内部，您可以直接使用 `docker commit` 来保存修改：

### 提交 Web 容器修改

```bash
# 保存当前 Web 容器的修改为新版本
docker commit zencart-web zencart-web:v1.1

# 或者添加说明
docker commit -m "添加新功能" zencart-web zencart-web:v1.1
```

### 提交数据库修改

```bash
# 保存当前数据库容器的修改
docker commit zencart-mysql zencart-mysql:v1.1
```

### 更新 docker-compose.yml 使用新版本

```yaml
services:
  web:
    image: zencart-web:v1.1 # 使用新版本
    # ...
  db:
    image: zencart-mysql:v1.1 # 使用新版本
    # ...
```

## 📁 文件结构

```
zen-cart-v1.5.7c-init/
├── Dockerfile.web          # Web 镜像构建文件
├── Dockerfile.mysql        # MySQL 镜像构建文件
├── docker-compose.yml      # Docker Compose 配置
├── build-images.sh         # 镜像构建脚本
├── start.sh                # 环境启动脚本
├── README-Docker.md        # 本说明文件
└── [zen-cart-files...]     # Zen Cart 源码文件
```

## 🔒 权限配置

镜像已经预配置了正确的文件权限：

- Web 文件所有者: www-data:www-data
- 可写目录权限: 777 (logs, cache)
- 其他文件权限: 755

## 🛠️ 开发建议

1. **修改代码**: 直接在运行的容器中修改，然后使用 `docker commit` 保存
2. **数据库修改**: 通过 phpMyAdmin 或命令行修改，然后提交数据库容器
3. **版本管理**: 使用标签来管理不同版本的镜像
4. **备份**: 定期导出镜像作为备份

## ⚠️ 注意事项

- 容器重启后未提交的修改会丢失
- 建议在重要修改后及时使用 `docker commit` 保存
- 密码仅用于开发环境，生产环境请修改为安全密码
