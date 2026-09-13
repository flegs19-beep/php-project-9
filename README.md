[![Actions Status](https://github.com/flegs19-beep/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/flegs19-beep/php-project-9/actions)
[![lint](https://github.com/flegs19-beep/php-project-9/actions/workflows/lint.yml/badge.svg)](https://github.com/flegs19-beep/php-project-9/actions/workflows/lint.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=coverage)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Duplicated Lines (%)](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=duplicated_lines_density)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Lines of Code](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=ncloc)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Maintainability Issues](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=software_quality_maintainability_issues)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Reliability Issues](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=software_quality_reliability_issues)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Security Issues](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=software_quality_security_issues)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Technical Debt](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=sqale_index)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=flegs19-beep_php-project-9\&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=flegs19-beep_php-project-9)

# Анализатор страниц

Анализатор страниц — веб-приложение для проверки сайтов на SEO-пригодность.

Приложение позволяет добавлять сайты, запускать проверки и просматривать код ответа, `h1`, `title` и `description`.

## Готовое приложение

[Открыть Page Analyzer](https://php-project-9-production-c0b7.up.railway.app/)

## Системные требования

* PHP 8.4
* Composer
* Node.js 24
* npm
* PostgreSQL

## Установка

```bash
git clone https://github.com/flegs19-beep/php-project-9.git
cd php-project-9
make setup
```

Создайте базу данных PostgreSQL и укажите переменную окружения `DATABASE_URL`.

Создайте таблицы:

```bash
psql "$DATABASE_URL" < database.sql
```

## Запуск

```bash
make start
```

После запуска приложение будет доступно по адресу:

```text
http://localhost:8000
```

## Проверка кода

```bash
make lint
```
