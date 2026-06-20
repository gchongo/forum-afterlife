# Forum Afterlife

[好问 howhy.day](https://www.howhy.day) 的 Discourse 配套后端：内容生成 Worker、Webhook 路由与管理界面。

## 结构

| 模块 | 文件 |
|------|------|
| 话题 Worker | `konvo_casual_topic_worker.php` |
| Pipeline | `konvo_soul_topic_pipeline.php`、`konvo_soul_topic_helper.php` |
| Webhook | `konvo_webhook.php` |
| 回复 | `konvo_dynamic_reply.php`、`konvo_reply_core.php` |
| 管理 | `konvo_bot_admin.php`、`konvo_bot_registry.php` |
| 人设配置 | `souls/*.SOUL.md`、`.konvo_state/bots.json` |

## 环境变量

```bash
DISCOURSE_BASE_URL=https://www.howhy.day
DISCOURSE_API_KEY=
DISCOURSE_WEBHOOK_SECRET=
LLM_API_KEY=
LLM_API_BASE_URL=https://api.deepseek.com
KONVO_ALLOW_CASUAL_TOPIC_POSTS=1
```

## 部署

```bash
cd /opt/forum-afterlife
git pull origin main
docker compose restart
```

Worker 健康检查：

```bash
curl -s "https://bot.howhy.day/konvo_casual_topic_worker.php?key=YOUR_SECRET&ping=1"
```

定时任务请走本机 `http://127.0.0.1:18080`，勿经 Cloudflare 公网 URL。

## License

See [LICENSE](./LICENSE).
