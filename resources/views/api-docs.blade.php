<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex" />
    <title>Loomora API</title>
    <style>
      /* Loomora palette (Elemental Edition): ivory paper, forest ink and primary, peach accent. */
      .light-mode {
        --scalar-background-1: #fcfbf8;
        --scalar-background-2: #f7f5ef;
        --scalar-background-3: #efece3;
        --scalar-color-1: #132a23;
        --scalar-color-2: #54685b;
        --scalar-color-3: #54685b;
        --scalar-color-accent: #1d5039;
        --scalar-background-accent: #e6f0eb;
        --scalar-border-color: #e1e8e1;
      }
      .light-mode .t-doc__sidebar {
        --scalar-sidebar-background-1: #f7f5ef;
        --scalar-sidebar-item-active-background: #e6f0eb;
        --scalar-sidebar-color-active: #1d5039;
      }
    </style>
  </head>
  <body>
    <script
      id="api-reference"
      data-url="{{ route('docs.spec') }}"
      data-configuration='{"theme":"none","forceDarkModeState":"light","hideDarkModeToggle":true,"withDefaultFonts":false,"showDeveloperTools":"never","agent":{"disabled":true},"metaData":{"title":"Loomora API"}}'
    ></script>
    <script
      src="https://cdn.jsdelivr.net/npm/@scalar/api-reference@1.78.0/dist/browser/standalone.js"
      integrity="sha384-cnEoXciWDGHIneAhs8XGPl143JvH/wcjYOm32qUmBmneG0OUgCS7XTxrI3OLrrbX"
      crossorigin="anonymous"
    ></script>
  </body>
</html>
