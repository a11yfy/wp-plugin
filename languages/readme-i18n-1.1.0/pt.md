<!-- a11yfy readme 1.1.0 új blokkjai — pt (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — új bekezdés

Três modos de funcionamento: **automático** (cada novo carregamento é corrigido), **manual** (escolhe o que corrigir) e **a pedido** — os visitantes que clicarem num PDF ainda não acessível podem solicitar uma versão acessível numa caixa de diálogo acessível e receber um e-mail quando esta estiver pronta, para que só pague pelos documentos de que as pessoas realmente precisam.

### External Services — módosult bullet

* **Quando corrige um PDF** (manualmente, através de ações em massa, pelo modo automático que ativou ou quando um visitante solicita uma versão acessível no modo a pedido que ativou): o próprio ficheiro PDF, o seu nome e a sua chave de API são enviados para `https://a11yfy.com/v1/jobs`. O processamento ocorre na UE. O endereço de e-mail do solicitante é armazenado apenas no seu site e nunca é enviado para a API do a11yfy.

### FAQ — új kérdés + válasz

= Como funciona o modo "A pedido"? =

Quando um visitante clica numa ligação para um PDF que não passou na verificação prévia de acessibilidade, surge uma caixa de diálogo acessível. O visitante pode abrir o documento tal como está ou solicitar uma versão acessível, introduzindo o seu endereço de e-mail. O plugin corrige o documento uma única vez — independentemente de quantos visitantes o solicitarem — e envia um e-mail a todos os que o pediram assim que a versão acessível estiver disponível. A partir desse momento, todos os visitantes recebem o ficheiro acessível na mesma ligação, sem qualquer caixa de diálogo. O endereço de e-mail é utilizado exclusivamente para esta notificação, nunca é enviado para a API do a11yfy e é eliminado automaticamente após 30 dias. Os textos da caixa de diálogo, o e-mail de notificação e o estilo dos botões podem ser personalizados em a11yfy → Definições.

### Changelog — 1.1.0

= 1.1.0 =
* Novo modo de funcionamento "A pedido": quando um visitante clica num PDF que ainda não está acessível, uma caixa de diálogo acessível oferece a opção de abrir o documento tal como está ou de solicitar uma versão acessível por e-mail. A correção só é executada quando existe uma necessidade real.
* Os visitantes são notificados por e-mail assim que a versão acessível fica pronta; todos os textos da caixa de diálogo (incluindo a nota de privacidade) e o e-mail de notificação são totalmente personalizáveis nas Definições.
* A caixa de diálogo herda a tipografia do seu tema e, nos temas de bloco, os botões seguem automaticamente o estilo dos botões do tema; em alternativa, pode escolher uma cor de destaque.
* Se o saldo de créditos não cobrir um pedido de visitante, o pedido fica em espera, o proprietário do site é avisado por e-mail e a correção inicia-se automaticamente quando houver créditos suficientes disponíveis.
* Os endereços de e-mail dos solicitantes são armazenados apenas até ao envio da notificação (retenção de 30 dias), com suporte para exportação e eliminação de dados pessoais do WordPress.
