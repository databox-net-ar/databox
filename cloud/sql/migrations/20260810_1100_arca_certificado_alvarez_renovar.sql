-- Renovacion del par (clave privada RSA + certificado X.509) del certificado
-- de Arca de "Alvarez" (CUIL 20248369451 - Alvarez Leonardo Javier). El
-- certificado anterior vencio y se rerenovado en el portal fiscal de AFIP el
-- 2026-08-10 (nueva vigencia 2026-08-10 -> 2028-08-09; issuer=CN=Computadores,
-- O=AFIP, C=AR).
--
-- Ademas borra la cache de tickets WSAA (arca_ta_cache) del certificado
-- para forzar que la proxima llamada a AFIP negocie un TA nuevo firmado con
-- la llave nueva. Sin esto, los tickets cacheados (firmados con la llave
-- anterior) siguen usandose hasta expirar solos (hasta 12h) y las FECAESolicitar
-- rebotan con "cert vencido" a pesar de que la fila ya tenga el par nuevo.
--
-- Se identifica el certificado por `nombre = 'Alvarez'` (no por id, que puede
-- diferir dev vs prod). Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

UPDATE arca_certificados
   SET llave = '-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC1CMwFrM+Ur10d
eFr/uNeKKiG/4tzR7SKREbZ5oUVXilykGDR1s4CnnmXhagHWbND4d5EHSJhMM7IO
4FPesll43u4gh7haP2JWjwlKOeajPE3xauXW9suzG2ZZo/JNowMswAQIOgdXeYS7
ONVt3WWiL9iytb6b0YV6B4bDPmK9DZny0HOx97g6wRgKE/otyRiRBdw5M7MJG8zX
WtJR/87TKSsrdxYcg1pOLyFcRhc5cusJH17u4WSJvTvZDSwn0c4uAJYohYxIovAU
WjsBLabIlshtMS5T7JVXmAGhrLOcDZp5JC2mkBFpuZgI25L/vCcNzYveD9B/NRL0
k1edjPUTAgMBAAECggEAREEV0wntlaxsWgEXphSFx0TNRrB8+vUCNFnOR5tjLncv
KHsrxDiySAAzf0JdgP+z5goGdw2KxigzeOJhHLR3gVfjxgYFnpkJNYNmSEL+Twsc
g+n+0AZqlJO/CgsC/vx35PZcTtG6FOPvBDuQVITFndmWRQK647qiLXkK+S/bQFVu
zwTlG7gNZWWjJfoKcABqPgrkzfxppoyXjmatZsOL4mWNkKmoCn8iowPUH+9Ei79j
mmG5EOHSqiFRDQYny8oqOc3CsOjea0nWzVtwycNIIUgjQeqhIbApoDMHqqaDQL3o
lijr+tx4dlMV2mCZ7GmPJKFVyZzBpO1pU+bOHvctwQKBgQDlRbaBeMq5yY+eM+GL
i+x36Os2btlQN0rUAVN+fn0v8tozsxmAPfSp6bIsGz7eA2pDYLzrRiFALftsn4DU
yy0By7chb8HYvPqrJaCdvADDGdvDSvVDkyp03cplbmYhpl0B57rKKrMprSUzZ9JZ
p2q9ZSzFCkWVSe1HoGlIpehkYQKBgQDKI362R+rPKX9hyIzVnb1l8Duwa/HvKiXy
07SW+qHkfVmQ4z5CD5eeVRuw+EL9ITr7qfIWI16PbOIh9gQEtKL+1yJHA6J7W79K
Iapkoxfrr3lGxyUU7Xthpos14eGGNUq3gQliyBTGzWuaAIZjI7ZXBJ1lELRjc7PU
+KMschTN8wKBgAddMtx7vb8z6yoArpjl2KWNVKi97Lr3265tkHn6pBi7KykH8qS8
2LPwbqmeAmntICej3s2LxhuinnXBtcif8gUhvvMS/N3yS/bdUYhfdoLNvNJMAQ63
lmCEkzCo2BzylAAwqj4+Gt0W06AQEKCSkQoeSs7VYqDF7Bol29vagFlhAoGBAKyG
nx/1QfSO9qn/AjVQ7NaUtF1fxJ7c2obnKruyL3tVgZyoV/sKU95PxdLGEmb6dd1W
r8k1ZwADbv6Ne+CITJY2CIuUDpo7NImRMl2y1jfTDS/byUqTZztxamAS6uohkiQN
fnVRUGpd6fkHeawkTvz2c2BPYaeAmXysupi0xg0BAoGAbzjMTakqLQqNjXkDc323
NiIcvXFmYOGWuWaLPed6DU59WHwwOsrOZ1jh6qagU1TpXO0U1E3gvBO9o3+PL33p
2FQbSq8etJTV9Caf+tyoV8vm1pGKMinmsdOv8UpDVyV7B1eO9HI3vJzrDOJwFm+h
OndH5zIk8FCgzawJyj8XPGA=
-----END PRIVATE KEY-----',
       certificado = '-----BEGIN CERTIFICATE-----
MIIDQjCCAiqgAwIBAgIII8XTVhhcFpgwDQYJKoZIhvcNAQENBQAwMzEVMBMGA1UEAwwMQ29tcHV0
YWRvcmVzMQ0wCwYDVQQKDARBRklQMQswCQYDVQQGEwJBUjAeFw0yNjA4MTAxODU5NTNaFw0yODA4
MDkxODU5NTNaMC0xEDAOBgNVBAMMB2RhdGFib3gxGTAXBgNVBAUTEENVSVQgMjAyNDgzNjk0NTEw
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC1CMwFrM+Ur10deFr/uNeKKiG/4tzR7SKR
EbZ5oUVXilykGDR1s4CnnmXhagHWbND4d5EHSJhMM7IO4FPesll43u4gh7haP2JWjwlKOeajPE3x
auXW9suzG2ZZo/JNowMswAQIOgdXeYS7ONVt3WWiL9iytb6b0YV6B4bDPmK9DZny0HOx97g6wRgK
E/otyRiRBdw5M7MJG8zXWtJR/87TKSsrdxYcg1pOLyFcRhc5cusJH17u4WSJvTvZDSwn0c4uAJYo
hYxIovAUWjsBLabIlshtMS5T7JVXmAGhrLOcDZp5JC2mkBFpuZgI25L/vCcNzYveD9B/NRL0k1ed
jPUTAgMBAAGjYDBeMAwGA1UdEwEB/wQCMAAwHwYDVR0jBBgwFoAUKw0vyN9h/QjJThHQNZMEbY5b
0G4wHQYDVR0OBBYEFIXsgg+GFEo6uwGXrN/s3vK8zKCDMA4GA1UdDwEB/wQEAwIF4DANBgkqhkiG
9w0BAQ0FAAOCAQEAiz4xs0GIOv7yBPLnq0ddT8y7qAZiGvtgFo8I2XK+sgoqwsbp91KUma1S2D9X
ERWaFaw3BuD4KEN4l/bgvlzLPOJQDCLpH4Plfas34Wi01fySQZgzQ+BucTPzITRPTeR04xZR6KuU
OD/SEoS1BezXedVDyNX7PuAkU3dFqt7pJQaoi7MpryywrA0YexZULCSbd9cH18ql0+8ZNap0OAsQ
BkdDyr4nVIHmQikW0AfIypJu3IpsQzBogiy8iNfPmVNxUylmb0QeMr6lZYOTdmuL9S96MBNFo6z1
5JGAc0AYluNlj/i1diIcXI47+sJTytsvSDU8u4YOK+rw/4K2o9UatQ==
-----END CERTIFICATE-----',
       actualizado = NOW()
 WHERE nombre = 'Alvarez';

DELETE FROM arca_ta_cache
 WHERE certificado_id IN (SELECT id FROM arca_certificados WHERE nombre = 'Alvarez');
