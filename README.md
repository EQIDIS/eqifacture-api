# EqiFacture SAT CFDI Proxy API

**Stateless proxy** between clients (Odoo, Python, JS) and SAT portal.

## Key Features

- ✅ **Stateless** - No data stored, no database
- ✅ **Two Methods** - Scraping (fast, sync) + WebService (massive, async)
- ✅ **Direct Response** - XML/PDF content returned in JSON (Base64)
- ✅ **No Auth Required** - FIEL sent in each request
- ✅ **Scalable** - Horizontally scale with Docker

## API Endpoints

### Scraping (Synchronous, fast, <500 CFDIs)

| Method | Endpoint                         | Description                  |
| ------ | -------------------------------- | ---------------------------- |
| POST   | `/api/v1/cfdis/query`            | Query CFDIs (metadata only)  |
| POST   | `/api/v1/cfdis/download`         | Download CFDIs by date range |
| POST   | `/api/v1/cfdis/download-by-uuid` | Download specific CFDIs      |

### WebService (Asynchronous, up to 200k CFDIs)

| Method | Endpoint               | Description                             |
| ------ | ---------------------- | --------------------------------------- |
| POST   | `/api/v1/ws/solicitar` | Create download request → `request_id`  |
| POST   | `/api/v1/ws/verificar` | Check status → `package_ids` when ready |
| POST   | `/api/v1/ws/descargar` | Download packages (ZIP with XMLs)       |

### Health

| Method | Endpoint         | Description  |
| ------ | ---------------- | ------------ |
| GET    | `/api/v1/health` | Health check |

## 🚀 Cómo Correr la API

Tienes 3 opciones principales para ejecutar la API:

### Opción 1: Desarrollo (Rápida)

_Ideal para probar cambios o ejecutar localmente._

```bash
# 1. Instalar dependencias
composer install

# 2. Iniciar servidor
php artisan serve
```

- **URL**: `http://localhost:8000/api/v1`
- **Swagger**: `http://localhost:8000/api/documentation`

---

### Opción 2: Producción (Docker) - Recomendada

_Ideal para desplegar, ya está optimizado y aislado._

```bash
# 1. Iniciar contenedores en segundo plano
docker-compose up -d --build

# 2. Verificar estado
docker-compose ps
```

- **URL**: `http://localhost:8000`
- **Logs**: `docker-compose logs -f`

---

### Opción 3: Producción (Manual / Servidor)

_Si prefieres correrlo directo en un servidor Ubuntu/Debian etc._

1. **Configuración de producción**:

    ```bash
    cp .env.production .env
    ```

2. **Optimizar carga (CRÍTICO)**:

    ```bash
    composer install --no-dev --optimize-autoloader
    php artisan config:cache
    php artisan route:cache
    ```

3. **Iniciar servidor**:
    ```bash
    php artisan serve --host=0.0.0.0 --port=8000
    # Nota: En un servidor real, usar Nginx + PHP-FPM
    ```

Consideraciones para Opción 3:

- Si modificas el `.env`, recuerda limpiar caché: `php artisan optimize:clear`
- Asegura que la carpeta `storage/logs` tenga permisos de escritura.

---

## Complete Client Flow

```
┌─────────────────────────────────────────────────────────────────┐
│  CLIENTE (Odoo, Python, JavaScript, etc.)                       │
└─────────────────────────────────────────────────────────────────┘
                              │
    ┌─────────────────────────▼─────────────────────────┐
    │  PASO 1: Query - Consultar qué CFDIs existen      │
    │                                                    │
    │  POST /api/v1/cfdis/query                         │
    │  → Recibe: metadata (UUID, RFC, total, fecha...)  │
    │  → No descarga archivos aún                       │
    └─────────────────────────┬─────────────────────────┘
                              │
    ┌─────────────────────────▼─────────────────────────┐
    │  PASO 2: (Opcional) Filtrar en el cliente         │
    │                                                    │
    │  - Descartar los que ya tengo                     │
    │  - Filtrar por emisor/receptor                    │
    │  - Filtrar por monto                              │
    └─────────────────────────┬─────────────────────────┘
                              │
    ┌─────────────────────────▼─────────────────────────┐
    │  PASO 3: Download - Descargar XMLs/PDFs           │
    │                                                    │
    │  POST /api/v1/cfdis/download (por fechas)         │
    │  ó                                                │
    │  POST /api/v1/cfdis/download-by-uuid (específicos)│
    │                                                    │
    │  → Recibe: archivos en Base64                     │
    └─────────────────────────┬─────────────────────────┘
                              │
    ┌─────────────────────────▼─────────────────────────┐
    │  PASO 4: Procesar en el cliente                   │
    │                                                    │
    │  - Decodificar Base64                             │
    │  - Parsear XML                                    │
    │  - Guardar en base de datos local                 │
    │  - Procesar contablemente                         │
    └─────────────────────────────────────────────────────┘
```

### ¿Por qué separar Query de Download?

| Escenario                 | Endpoint                                   |
| ------------------------- | ------------------------------------------ |
| Ver qué hay sin descargar | Solo **Query**                             |
| Descargar todo            | **Download** directo                       |
| Descargar solo nuevos     | **Query** → Filtrar → **Download by UUID** |

---

## Python SDK Example (Scraping)

```python
import requests
import base64

class SatDownloader:
    """Cliente Python para EqiFacture SAT CFDI Proxy API"""

    def __init__(self, api_url, cert_path, key_path, passphrase):
        self.api_url = api_url
        self.cert_path = cert_path
        self.key_path = key_path
        self.passphrase = passphrase

    def _get_files(self):
        return {
            "certificate": open(self.cert_path, "rb"),
            "private_key": open(self.key_path, "rb"),
        }

    def query(self, start_date, end_date, tipo="recibidos"):
        """PASO 1: Query - Ver qué CFDIs existen"""
        response = requests.post(
            f"{self.api_url}/cfdis/query",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "start_date": start_date,
                "end_date": end_date,
                "download_type": tipo,
            }
        )
        return response.json()["data"]["cfdis"]

    def download_by_dates(self, start_date, end_date, tipo="recibidos", max_results=500):
        """PASO 3a: Descargar por rango de fechas"""
        response = requests.post(
            f"{self.api_url}/cfdis/download",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "start_date": start_date,
                "end_date": end_date,
                "download_type": tipo,
                "resource_types": "xml",
                "max_results": max_results,
            }
        )
        return response.json()["data"]["files"]

    def download_by_uuids(self, uuids, tipo="recibidos"):
        """PASO 3b: Descargar por UUIDs específicos"""
        response = requests.post(
            f"{self.api_url}/cfdis/download-by-uuid",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "uuids": ",".join(uuids),
                "download_type": tipo,
                "resource_types": "xml",
            }
        )
        return response.json()["data"]["files"]


# ============ USO COMPLETO ============

downloader = SatDownloader(
    api_url="http://localhost:8000/api/v1",
    cert_path="/path/to/fiel.cer",
    key_path="/path/to/fiel.key",
    passphrase="Tu-Password"
)

# 1. Consultar qué CFDIs existen
cfdis = downloader.query("2025-12-01", "2025-12-31")
print(f"Encontrados: {len(cfdis)} CFDIs")

# 2. Filtrar (opcional) - solo los que no tengo
uuids_que_ya_tengo = ["uuid1", "uuid2"]
nuevos = [c for c in cfdis if c["uuid"] not in uuids_que_ya_tengo]

# 3. Descargar solo los nuevos
if nuevos:
    uuids_a_descargar = [c["uuid"] for c in nuevos]
    files = downloader.download_by_uuids(uuids_a_descargar)

    # 4. Procesar cada archivo
    for file in files:
        xml_content = base64.b64decode(file["content"])
        uuid = file["uuid"]
        metadata = file["metadata"]

        # Guardar archivo
        with open(f"{uuid}.xml", "wb") as f:
            f.write(xml_content)

        print(f"✓ {uuid}: ${metadata['total']}")
```

---

## WebService SDK Example (Massive Downloads)

⚠️ **El WebService es ASÍNCRONO** - puede tardar de minutos a 72 horas.

```python
import requests
import base64
import time
import zipfile
import io

class SatWebServiceDownloader:
    """Cliente Python para WebService del SAT (Descarga Masiva)"""

    def __init__(self, api_url, cert_path, key_path, passphrase):
        self.api_url = api_url
        self.cert_path = cert_path
        self.key_path = key_path
        self.passphrase = passphrase

    def _get_files(self):
        return {
            "certificate": open(self.cert_path, "rb"),
            "private_key": open(self.key_path, "rb"),
        }

    def solicitar(self, start_date, end_date, download_type="recibidos",
                  service_type="cfdi", request_type="cfdi"):
        """Paso 1: Crear solicitud de descarga masiva"""
        response = requests.post(
            f"{self.api_url}/ws/solicitar",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "start_date": start_date,
                "end_date": end_date,
                "download_type": download_type,
                "service_type": service_type,
                "request_type": request_type,
            }
        )
        result = response.json()
        if not result["success"]:
            raise Exception(f"Error: {result.get('errors')}")
        return result["data"]["request_id"]

    def verificar(self, request_id, service_type="cfdi"):
        """Paso 2: Verificar estado de la solicitud"""
        response = requests.post(
            f"{self.api_url}/ws/verificar",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "request_id": request_id,
                "service_type": service_type,
            }
        )
        return response.json()["data"]

    def descargar(self, package_ids, service_type="cfdi"):
        """Paso 3: Descargar paquetes (ZIPs con XMLs)"""
        response = requests.post(
            f"{self.api_url}/ws/descargar",
            files=self._get_files(),
            data={
                "passphrase": self.passphrase,
                "package_ids": ",".join(package_ids),
                "service_type": service_type,
            }
        )
        return response.json()["data"]["packages"]

    def download_with_polling(self, start_date, end_date, poll_interval=300):
        """Helper: Descarga completa con polling automático"""
        # 1. Solicitar
        request_id = self.solicitar(start_date, end_date)
        print(f"📋 Solicitud creada: {request_id}")

        # 2. Polling hasta que esté listo
        while True:
            result = self.verificar(request_id)
            status = result["status"]
            print(f"⏳ Estado: {status}")

            if status == "finished":
                break
            elif status in ["failure", "rejected", "expired"]:
                raise Exception(f"Solicitud falló: {result['message']}")

            print(f"   Esperando {poll_interval}s...")
            time.sleep(poll_interval)

        # 3. Descargar paquetes
        package_ids = result["package_ids"]
        print(f"📦 Descargando {len(package_ids)} paquetes...")
        return self.descargar(package_ids)


# ============ USO COMPLETO ============

ws_downloader = SatWebServiceDownloader(
    api_url="http://localhost:8000/api/v1",
    cert_path="/path/to/fiel.cer",
    key_path="/path/to/fiel.key",
    passphrase="Tu-Password"
)

# Descargar TODO un año (hasta 200k CFDIs)
packages = ws_downloader.download_with_polling(
    start_date="2025-01-01 00:00:00",
    end_date="2025-12-31 23:59:59",
    poll_interval=300  # Verificar cada 5 minutos
)

# Procesar cada paquete (ZIP con XMLs)
for pkg in packages:
    zip_content = base64.b64decode(pkg["content_base64"])

    with zipfile.ZipFile(io.BytesIO(zip_content)) as zf:
        for xml_name in zf.namelist():
            xml_content = zf.read(xml_name)
            print(f"✓ Extraído: {xml_name}")
            # Procesar XML...
```

### ¿Cuándo usar cada método?

| Escenario                    | Método         | Endpoint Prefix |
| ---------------------------- | -------------- | --------------- |
| Consultas diarias (<500)     | **Scraping**   | `/cfdis/*`      |
| Descarga masiva (>500, años) | **WebService** | `/ws/*`         |
| Necesito respuesta inmediata | **Scraping**   | `/cfdis/*`      |
| Solo Metadata (sin XMLs)     | **WebService** | `/ws/*`         |

---

## Odoo Integration Example

```python
# En modelo Odoo
import requests
import base64
from odoo import models, fields, api

class CfdiDownloader(models.Model):
    _name = 'cfdi.downloader'

    def download_cfdis(self, start_date, end_date):
        company = self.env.company

        response = requests.post(
            f"{company.sat_proxy_url}/api/v1/cfdis/download",
            files={
                "certificate": open(company.fiel_cer_path, "rb"),
                "private_key": open(company.fiel_key_path, "rb"),
            },
            data={
                "passphrase": company.fiel_password,
                "start_date": start_date,
                "end_date": end_date,
                "download_type": "recibidos",
                "resource_types": "xml",
            }
        )

        for file in response.json()["data"]["files"]:
            xml_content = base64.b64decode(file["content"])
            self._process_cfdi(file["uuid"], xml_content, file["metadata"])

    def _process_cfdi(self, uuid, xml_content, metadata):
        # Crear attachment en Odoo
        self.env['ir.attachment'].create({
            'name': f'{uuid}.xml',
            'datas': base64.b64encode(xml_content),
            'mimetype': 'application/xml',
        })
```

---

## Response Formats

### Query Response

```json
{
    "success": true,
    "data": {
        "count": 6,
        "cfdis": [
            {
                "uuid": "b456fff2-0e87-465b-83a9-0493469cb153",
                "rfc_emisor": "GHO210920IRA",
                "nombre_emisor": "GRAXI HOLDING",
                "total": "$4,029.98",
                "fecha_emision": "2025-12-18T11:40:34",
                "estado_comprobante": "Vigente",
                "has_xml": true,
                "has_pdf": true
            }
        ]
    }
}
```

### Download Response

```json
{
    "success": true,
    "data": {
        "count": 3,
        "files": [
            {
                "uuid": "b456fff2-0e87-465b-83a9-0493469cb153",
                "type": "xml",
                "content": "PD94bWwgdmVyc2lvbj0iMS4wIi...",
                "size": 15234,
                "metadata": {
                    "uuid": "b456fff2-0e87-465b-83a9-0493469cb153",
                    "total": "$4,029.98"
                }
            }
        ]
    }
}
```

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Clients                              │
│  (Odoo, Python, JavaScript, etc.)                           │
└────────────────────────────┬────────────────────────────────┘
                             │
                    HTTP + FIEL credentials
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                    EqiFacture Proxy API                      │
│                                                              │
│  • No Database        • No File Storage                     │
│  • Stateless          • Horizontally Scalable               │
└────────────────────────────┬────────────────────────────────┘
                             │
                    FIEL Authentication
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                    SAT Portal                                │
│              portalcfdi.facturaelectronica.sat.gob.mx       │
└─────────────────────────────────────────────────────────────┘
```

---

## Security Notes

- ⚠️ FIEL credentials used **only** during the request
- ⚠️ Credentials **never** stored or logged
- ⚠️ Use HTTPS in production
- ⚠️ Consider IP whitelisting

## License

MIT
