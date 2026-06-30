

<main>
  <section id="top" class="tracker_hero">
    <div class="tracker_hero_image" aria-hidden="true"></div>
    <div class="tracker_hero_overlay" aria-hidden="true"></div>

    <div class="tracker_wrap tracker_hero_content">
      <span class="tracker_badge">
        <span class="tracker_pulse"></span>
        Central de Rastreamento SportMoto
      </span>

      <h1>
        Acompanhe seu pedido <br />
        <span class="tracker_text_gradient">em tempo real</span>
      </h1>

      <p>
        Insira o número do seu pedido abaixo e veja exatamente onde sua encomenda
        está. Tudo de forma rápida, segura e atualizada.
      </p>
    </div>

    <div id="rastrear" class="tracker_wrap tracker_iframe_area">
      <div class="tracker_reveal tracker_iframe_card">
        <div class="tracker_iframe_box">
          <iframe
            title="Rastreamento de pedido SportMoto"
            src="https://www.linkcorreios.com.br/"
            loading="lazy"></iframe>
        </div>
        <p class="tracker_secure_note">
          <span data-tracker-icon="shield"></span>
          Seus dados são processados de forma segura.
        </p>
      </div>
    </div>
  </section>

  <section id="avisos" class="tracker_wrap tracker_section tracker_notices">
    <div class="tracker_section_head tracker_reveal">
      <h2>Informações importantes</h2>
      <p>Tudo o que você precisa saber para acompanhar seu pedido sem dor de cabeça.</p>
    </div>

    <div class="tracker_notice_grid">
      <article class="tracker_reveal tracker_delay_1 tracker_card tracker_notice_card">
        <div class="tracker_card_icon" data-tracker-icon="clock"></div>
        <h3>Código de rastreio</h3>
        <p>Enviado em até 02 dias úteis após a confirmação do pagamento.</p>
      </article>

      <article class="tracker_reveal tracker_delay_2 tracker_card tracker_notice_card">
        <div class="tracker_card_icon" data-tracker-icon="mail"></div>
        <h3>Notificação por e-mail</h3>
        <p>Você recebe um e-mail com o título "Oba! Seu produto está a caminho!".</p>
      </article>

      <article class="tracker_reveal tracker_delay_3 tracker_card tracker_notice_card">
        <div class="tracker_card_icon" data-tracker-icon="message"></div>
        <h3>WhatsApp oficial</h3>
        <p>Mensagem do nosso canal: (51) 99721-2226. Único número oficial.</p>
      </article>
    </div>

    <div class="tracker_reveal tracker_alert_card">
      <div class="tracker_alert_icon" data-tracker-icon="alert"></div>
      <div>
        <h3>Atenção: não recebeu o e-mail?</h3>
        <p>Verifique:</p>
        <ul>
          <li><span data-tracker-icon="package"></span> Se seu e-mail foi cadastrado corretamente no momento da compra;</li>
          <li><span data-tracker-icon="package"></span> Se recebeu o e-mail de ativação do seu cadastro pós-compra;</li>
          <li><span data-tracker-icon="package"></span> Verifique também sua caixa de SPAM.</li>
        </ul>
        <p class="tracker_alert_footer">
          <span data-tracker-icon="shield"></span>
          Não passamos informações de rastreamento de um pedido para terceiros.
        </p>
      </div>
    </div>
  </section>

  <section class="tracker_timeline_section">
    <div class="tracker_wrap tracker_timeline_grid">
      <div class="tracker_reveal">
        <span class="tracker_eyebrow"><span data-tracker-icon="calendar"></span> Como contar o prazo</span>
        <h2>O prazo é em <span class="tracker_text_gradient">dias úteis</span></h2>
        <p>
          O prazo de entrega informado <strong>não inclui finais de semana e feriados</strong>.
          A contagem começa após a coleta/postagem do pedido.
        </p>

        <div class="tracker_example_card">
          <p class="tracker_example_title">Exemplo prático</p>
          <p>
            Pedido coletado no dia <strong>05 (quinta-feira)</strong> com prazo de
            <strong>12 dias úteis</strong>:
          </p>
          <span>Previsão: dia 23 (segunda-feira)</span>
        </div>
      </div>

      <div class="tracker_reveal tracker_delay_1 tracker_calendar_card">
        <div class="tracker_calendar_top">
          <p>Prazo de entrega</p>
          <span>Exemplo</span>
        </div>
        <div class="tracker_calendar_week">
          <span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span><span>D</span>
        </div>
        <div class="tracker_calendar_days" id="tracker_calendar_days"></div>
        <div class="tracker_calendar_legend">
          <span><i class="tracker_legend_collect"></i> Coleta (dia 5)</span>
          <span><i class="tracker_legend_delivery"></i> Entrega (dia 23)</span>
          <span><i class="tracker_legend_weekend"></i> Fim de semana</span>
        </div>
      </div>
    </div>
  </section>

  <section id="frete" class="tracker_wrap tracker_section tracker_freight tracker_freight_premium">
    <div class="tracker_section_head tracker_reveal">
      <span class="tracker_eyebrow"><span data-tracker-icon="pin"></span> Frete grátis por região</span>
      <h2>Confira o valor mínimo para liberar <span class="tracker_text_gradient">frete grátis</span></h2>
      <p>Selecione seu estado no mapa ou escolha na lista. Em poucos segundos você sabe quanto falta para aproveitar o benefício no seu pedido.</p>
    </div>

    <div class="tracker_reveal tracker_freight_experience">
      <div class="tracker_map_card tracker_freight_map_panel">
        <div class="tracker_map_intro">
          <span class="tracker_map_badge"><span data-tracker-icon="truck"></span> Consulta rápida</span>
          <h3>Toque, clique ou selecione seu estado</h3>
          <p>No computador, passe o mouse pelo mapa. No celular, toque no estado ou use o seletor abaixo.</p>
        </div>

        <div id="tracker_brazil_map" class="tracker_map_wrap" aria-label="Mapa do Brasil com valores de frete grátis por estado"></div>

        <p class="tracker_map_disclaimer">Valores válidos para envios padrão. Promoções, produtos especiais e regiões específicas podem ter condições diferentes.</p>
      </div>

      <aside class="tracker_freight_panel" aria-live="polite">
        <span class="tracker_panel_label">Seu benefício</span>
        <h3 id="tracker_selected_state">Escolha seu estado</h3>
        <p id="tracker_selected_value">Veja na hora o valor mínimo para liberar o frete grátis na sua região.</p>

        <label for="tracker_state_select" class="tracker_state_label">Selecionar estado</label>
        <select id="tracker_state_select" class="tracker_state_select">
          <option value="">Selecione uma opção</option>
        </select>

        <div class="tracker_quick_states" aria-label="Estados mais consultados">
          <button type="button" data-state="São Paulo">SP</button>
          <button type="button" data-state="Rio de Janeiro">RJ</button>
          <button type="button" data-state="Minas Gerais">MG</button>
          <button type="button" data-state="Paraná">PR</button>
          <button type="button" data-state="Rio Grande do Sul">RS</button>
        </div>

        <div class="tracker_freight_trust">
          <span data-tracker-icon="shield"></span>
          <p>Condição exibida antes da finalização do pedido, com cálculo validado conforme CEP, transportadora e campanha ativa.</p>
        </div>
      </aside>
    </div>
    <div id="tracker_map_tooltip" class="tracker_map_tooltip"></div>
  </section>

  <section id="faq" class="tracker_faq_section">
    <div class="tracker_wrap tracker_faq_wrap">
      <div class="tracker_section_head tracker_reveal">
        <h2>Perguntas frequentes</h2>
        <p>Tire suas dúvidas sobre entregas, prazos e devoluções.</p>
      </div>

      <div class="tracker_faq_list">
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Em quanto tempo recebo o código de rastreio?<span></span></button>
          <div><p>O código de rastreio do seu pedido é enviado em até 02 dias úteis após a confirmação do pagamento.</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Como vou saber que meu pedido foi postado?<span></span></button>
          <div><p>Você recebe duas notificações: um e-mail com o título “Oba! Seu produto está a caminho!” e uma mensagem do nosso canal oficial de WhatsApp pelo número (51) 99721-2226.</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Não recebi o e-mail, e agora?<span></span></button>
          <div><p>Confira se o e-mail foi cadastrado corretamente na compra, se você ativou seu cadastro pós-compra e verifique também sua caixa de SPAM.</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Como o prazo de entrega é contado?<span></span></button>
          <div><p>O prazo é sempre em dias úteis — não contam finais de semana e feriados. Exemplo: pedido coletado na quinta-feira dia 05, com prazo de 12 dias úteis, chega previsto para o dia 23 (segunda-feira).</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Vocês passam informações de rastreio por telefone?<span></span></button>
          <div><p>Não. Por segurança, não passamos informações de rastreamento de pedidos para terceiros. Tudo é enviado para o e-mail e WhatsApp cadastrados na compra.</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Como funcionam as devoluções?<span></span></button>
          <div><p>Você tem até 7 dias corridos após o recebimento para solicitar a devolução, conforme o Código de Defesa do Consumidor. O produto deve estar sem uso, na embalagem original e com todos os acessórios.</p></div>
        </article>
        <article class="tracker_reveal tracker_faq_item">
          <button type="button">Frete grátis está disponível para todo o Brasil?<span></span></button>
          <div><p>Sim! Oferecemos frete grátis a partir de um valor mínimo que varia por estado. No computador, use o mapa; no celular, toque no estado ou escolha sua região no seletor.</p></div>
        </article>
      </div>
    </div>
  </section>
</main>


