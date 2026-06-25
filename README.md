# databox



// esto sirve para enviar mensajes de telegram a javier de alertas

postman request POST 'https://api.telegram.org/bot8494205303:AAHyQ7umgHCvPG7e0mPsCNsZaLzcqsYCEhk/sendMessage' \
  --header 'Content-Type: application/json' \
  --body '{
    "chat_id": "636937794",
    "text": "¡Logrado! Mensaje enviado con éxito desde Postman a tu bot Sherlock."
}'