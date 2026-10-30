---
date: 2026-10-04
area: Integrations
---
Переподключение Google доступно в карточке сервиса, причина и журнал переведены; предупреждение на главной открывает нужную карточку — [интеграции](modules/integrations.md).
OAuth configuration gates card navigation through the native Material disabled state; CI accessibility inventory and contrast checks verify the unavailable state.
Недоступное подключение теперь объясняет причину и следующий шаг возле каждой кнопки; disabled-стиль унифицирован, описания Google-карточек выровнены. Добавлены синтетические сценарии подключённого Google, истёкшего доступа и ошибки журнала с обязательными screenshots и проверками без реального OAuth. CI 37221207402: all 12 configured-state scenarios passed; the four base parity cases reported only the intentionally removed href on the unavailable top Google action. Updated exactly that one entry in each desktop/mobile inventory; other roles, counters, URLs, axe and layout expectations remain unchanged. Connected/error mode labels are visible without manual assignment; focused mobile cards remain below the sticky header, with regression assertions.
