# Docker Commit 使用指南

## ✅ 当前状态确认

### **Web 容器 (zencart-web)**

- ✅ **完全支持 docker commit**
- ✅ 代码修改后可以保存到新镜像
- ✅ 无任何挂载，所有文件都在容器内

### **MySQL 容器 (zencart-mysql)**

- ⚠️ **部分支持 docker commit**
- ⚠️ MySQL 官方镜像使用 `VOLUME ["/var/lib/mysql"]` 导致数据无法通过 commit 保存

## 🔧 解决方案

### 方案一：Web 容器使用 docker commit，数据库使用传统备份

```bash
# 1. 保存 Web 代码修改
docker commit zencart-web zencart-web:v1.1

# 2. 导出数据库数据
docker exec zencart-mysql mysqldump -uroot -p123456 zencart > zencart_backup.sql

# 3. 在新环境中导入数据
docker exec zencart-mysql mysql -uroot -p123456 zencart < zencart_backup.sql
```

### 方案二：完全自定义 MySQL 镜像（推荐）

创建不使用 VOLUME 的自定义 MySQL 镜像：

```dockerfile
# Dockerfile.mysql-no-volume
FROM ubuntu:20.04

# 安装 MySQL
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server && \
    rm -rf /var/lib/apt/lists/*

# 配置 MySQL（不使用 VOLUME）
RUN mkdir -p /var/lib/mysql /var/run/mysqld && \
    chown -R mysql:mysql /var/lib/mysql /var/run/mysqld

# 初始化数据库
RUN mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql

COPY <<EOF /docker-entrypoint.sh
#!/bin/bash
mysqld_safe --user=mysql --datadir=/var/lib/mysql &
sleep 10
mysql -e "CREATE DATABASE IF NOT EXISTS zencart;"
mysql -e "CREATE USER IF NOT EXISTS 'zencart'@'%' IDENTIFIED BY '123456';"
mysql -e "GRANT ALL PRIVILEGES ON zencart.* TO 'zencart'@'%';"
mysql -e "FLUSH PRIVILEGES;"
wait
EOF

RUN chmod +x /docker-entrypoint.sh

EXPOSE 3306
CMD ["/docker-entrypoint.sh"]
```

## 📋 当前推荐工作流程

### 1. 开发代码修改

```bash
# 进入 Web 容器修改代码
docker exec -it zencart-web bash
# 在容器内修改 PHP 文件...

# 提交代码修改
docker commit zencart-web zencart-web:v1.1
```

### 2. 数据库操作

```bash
# 备份数据库
docker exec zencart-mysql mysqldump -uroot -p123456 --all-databases > backup.sql

# 在新环境恢复数据库
docker exec zencart-mysql mysql -uroot -p123456 < backup.sql
```

### 3. 完整环境打包

```bash
# 更新 docker-compose.yml 使用新版本
# 然后保存完整配置
tar -czf zencart-env-v1.1.tar.gz docker-compose.yml backup.sql
```

## 🎯 实际测试结果

✅ **Web 容器**: `docker commit zencart-web zencart-web:v1.1` - **完全工作**
❌ **MySQL 容器**: `docker commit zencart-mysql zencart-mysql:v1.1` - **数据不保存**（由于 VOLUME 限制）

## 💡 最佳实践建议

1. **代码修改**: 使用 `docker commit` 保存 Web 容器
2. **数据库**: 使用 `mysqldump` 备份/恢复
3. **完整环境**: 组合使用新镜像 + SQL 备份文件

这样可以实现您需要的功能：代码和数据都能保存和迁移！
