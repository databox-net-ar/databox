-- Renovacion del par (clave privada RSA + certificado X.509) del certificado
-- de Arca de "Alfatec" (CUIT 30718080599). El certificado anterior vencio y
-- se rerenovado en el portal fiscal de AFIP el 2026-08-10 (nueva vigencia
-- 2026-08-10 -> 2028-08-09; issuer=CN=Computadores, O=AFIP, C=AR).
--
-- Ademas borra la cache de tickets WSAA (arca_ta_cache) del certificado
-- para forzar que la proxima llamada a AFIP negocie un TA nuevo firmado con
-- la llave nueva. Sin esto, los tickets cacheados (firmados con la llave
-- anterior) siguen usandose hasta expirar solos (hasta 12h) y las FECAESolicitar
-- rebotan con "cert vencido" a pesar de que la fila ya tenga el par nuevo.
--
-- Se identifica el certificado por `nombre = 'Alfatec'` (no por id, que puede
-- diferir dev vs prod). Compatible con MySQL 8 (dev) y MariaDB 10.11 (prod).

UPDATE arca_certificados
   SET llave = '-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCqvQXnM6peG2Q4
8jBARKaqL/shLm4lPESYgU0A818vylgtPgZDyJA58GSSWMRqAjHD4KMw1mSi54Qs
n/+6W06m6dFcdePtQyBN2xhFbf7gxeg6Zi1Ia+k9E5NXSq+2174tBL1WT4KJDfNZ
7VBZRVZ0Jw9JLwV8GPW+OqGrryUfX8eaWsONKdIDcIsOU/AT+IwJv/E4KKDT5+zI
HpeHigoNd4UeVHCSX7pe6spmelQTrbGdvvYwFPlFkgCrazdY0163c0OtVp8n3e+8
vcE7Jt075J9MdNVO5Jo4bFAbHcidHqs+ZalomMfue2YTPCaf6m7qMUGbS0PtLHqV
XKmk7w4nAgMBAAECggEAUuXsE9GWCpMqGiwdGVc7xK3/NKEigZm9hH5jMO75bG7G
WVEgIZEj1b3OVvAajY37M6vCEFhIDIB0QGZ+97CVg51LNaVXwT8yVBrose0yL1hn
5NLvcZZTbIAwrdVpc1FU2O7QLzPzoS/Q0/zRYka0Lzk3xsS52QMVbgNxs7YaRByl
eV64MCxkQe7mGp1w0DFWImg+qr4+l3x/jjgB0RAQEGRL6aei52BukTowkkxFTVlV
UE3f1QGbDWnjUFZBgOjmcTOJSeN8UmeFsVXE6TEXRCkNyocjNTN3Esy0nPBFOZ34
XPj+pXplYGlK+kgZx5avWgeYxSp1xDZ34jQKdAOtIQKBgQDgdl+x2SdkVqDk4kYW
I9lDbtPYISUL1tNWhR/xEBmLhFJTlJ5gAyKZGEvlzhwNj+vD8373CpEIUd1FssHS
aOX1fhRgTZZ+4sgCNdZa4Yx4mhzeVdvFxO8mcNBDAD9MjkXGBwf8kV/kp98gsggD
tstaIU0T/Q3LEd3m5l24490nwwKBgQDCukO4qs0Rnl5fIusZtSY3SzkQ/QldI/6d
csl8p6GoZkpT9Ld55VxMOWNgMtSTeohVyiXdVVMngbaXS/92tTW6HDsdcwVMJkoo
J2Uc69mHUCJsloezf5twk1lw8w3WcHVwqkX+NOSM8ZlQtfnS6T8T4L70VGxN12EE
DeX2cyR9zQKBgQDE+hxwTkirXPpE4ezvcPYwnwl5GV0RTqyXuKuXOLGyJaS5hCqX
xyiNgSzZtk4X+LzFcOFymes8idrMV1qP804aaVIoUO5I22r5xZUem+BR1ayP0HjU
zUWxTj71DTp/TDse1PzFQC4O0uKUJqex2rAJoD+r0t5P3pYFExQcNJrXUQKBgHQ9
4MNSIoyL72X3YES+YIvNeclsY7SYEhxHM4QYRWZTebdYdFZt1oUiFPKOJVvMX6pm
u+e+UZ9ZzXfPxDZGwkXRKHDSAq2MheQmcDOtjvM5oPMVgPhkCpRPQastTGtgQpr4
6kNvq6d/abhGiVWgKylgll0gMG7fTWiwK0DNR1FZAoGBALmS6uvVPDMrCmVDSqnO
6EApvtDxTf5QPvvgUz4H7HoC1qRQB0SN+b+YCZfS/NH9r2lOvrG32LfGy/vganHV
xe0IdBKzOq4FHw7e3PYe2rcFqmcUfAzt8NH9xZu0+u+q2o8J20GeqtFooPynHYv6
HqWaZ8AX3GjpxA7R75lejbER
-----END PRIVATE KEY-----',
       certificado = '-----BEGIN CERTIFICATE-----
MIIDQjCCAiqgAwIBAgIIfJQ0Mid1I2wwDQYJKoZIhvcNAQENBQAwMzEVMBMGA1UEAwwMQ29tcHV0
YWRvcmVzMQ0wCwYDVQQKDARBRklQMQswCQYDVQQGEwJBUjAeFw0yNjA4MTAxODQ1MTRaFw0yODA4
MDkxODQ1MTRaMC0xEDAOBgNVBAMMB2RhdGFib3gxGTAXBgNVBAUTEENVSVQgMzA3MTgwODA1OTkw
ggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQCqvQXnM6peG2Q48jBARKaqL/shLm4lPESY
gU0A818vylgtPgZDyJA58GSSWMRqAjHD4KMw1mSi54Qsn/+6W06m6dFcdePtQyBN2xhFbf7gxeg6
Zi1Ia+k9E5NXSq+2174tBL1WT4KJDfNZ7VBZRVZ0Jw9JLwV8GPW+OqGrryUfX8eaWsONKdIDcIsO
U/AT+IwJv/E4KKDT5+zIHpeHigoNd4UeVHCSX7pe6spmelQTrbGdvvYwFPlFkgCrazdY0163c0Ot
Vp8n3e+8vcE7Jt075J9MdNVO5Jo4bFAbHcidHqs+ZalomMfue2YTPCaf6m7qMUGbS0PtLHqVXKmk
7w4nAgMBAAGjYDBeMAwGA1UdEwEB/wQCMAAwHwYDVR0jBBgwFoAUKw0vyN9h/QjJThHQNZMEbY5b
0G4wHQYDVR0OBBYEFLv8GAAktCtcy+9nUnaBGDYGHJRDMA4GA1UdDwEB/wQEAwIF4DANBgkqhkiG
9w0BAQ0FAAOCAQEAhYGsninzwIZaj6oWWEYohfyvaxG7yn8uRGxRV2YFHDBHJtIDUoll990dn8A6
bzNKBY9ASYcNTKgQ1emrZs5N71gmCl1RJ++XUPQB3QhDIj6By9K4wSMn8GRmNe17I4wj2BV5lQ+2
PSisWriaAjeIHwqkg+o0HgnrTEuKkgYSTZEUA+WYVkm+1bJPwgaydTwLgxz9DWqWGl1ud6dBJUzW
alef7NOaloj7zetUd94LOQBUZh6oQwDkDc6JowO4zm22HU/7Cf+w1IFUFRA+c39+LFQ4qp6GWx3B
f754+0W0uoeYW6IScKpESb3yjCsvyyI4ZFY1jyfx8kNkUMTo4UjjAw==
-----END CERTIFICATE-----',
       actualizado = NOW()
 WHERE nombre = 'Alfatec';

DELETE FROM arca_ta_cache
 WHERE certificado_id IN (SELECT id FROM arca_certificados WHERE nombre = 'Alfatec');
