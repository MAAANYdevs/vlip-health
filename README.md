# VLIP Health

WordPress-Plugin der VLIP GmbH. Es liest den Zustand einer WordPress-Installation
(Core, Plugins, Themes, PHP, Zertifikat, Sicherung) und gibt ihn am Endpunkt
`/wp-json/vlip/v1/health` aus. Nur lesend, nur mit Secret.

Dieses Repository ist die **Update-Quelle** des Plugins: `update.json` nennt die
aktuelle Version und die Zip des zugehörigen Releases. Installierte Plugins
fragen die Datei über WordPress' eigenen Updater ab und aktualisieren sich.

## Installation

**Per Composer (Bedrock und andere Composer-Installationen):**

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/MAAANYdevs/vlip-health" }],
"require": { "maaanydevs/vlip-health": "^1.1" }
```

Der Composer-Installer legt das Plugin unter `plugins/vlip-health/` ab. Damit
es ohne Klick aktiv ist, sorgt ein kleines MU-Plugin für die Aktivierung
(`vlip-health-loader.php`, siehe Dashboard-Repo). Neue Versionen kommen mit
einem Bump der `composer.lock`; zwischen zwei Deploys hält WordPress' Updater
das Plugin aktuell.

**Per Zip:**

Zip aus dem [jüngsten Release](https://github.com/MAAANYdevs/vlip-health/releases/latest)
über „Plugins → Installieren → Plugin hochladen" einspielen und aktivieren.
Das Secret kommt aus der Konstante `VLIP_HEALTH_SECRET` in der `wp-config.php`
oder aus der Umgebungsvariable gleichen Namens. Ohne Secret antwortet der
Endpunkt nicht.

## Neue Version veröffentlichen

1. Version im Plugin-Header und in `VLIP_HEALTH_VERSION` anheben, Datei als
   `vlip-health.php` ins Repo-Wurzelverzeichnis kopieren.
2. `update.json` auf die neue Version und die Release-URL setzen.
3. Committen und taggen: `git tag v1.2.0 && git push --tags`. Der Workflow baut
   die Zip und legt das Release an.

Die gepflegte Fassung des Plugins liegt im Dashboard-Repository; dieses
Repository ist Spiegel und Verteilweg.
