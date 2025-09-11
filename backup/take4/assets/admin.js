jQuery(document).ready(function ($) {
  const cache = {}; // タブごとのキャッシュ
  let isRunning = false; // 実行中フラグ

  // タブ切り替え
  $(".nav-tab").on("click", function (e) {
    e.preventDefault();
    const $this = $(this);
    const tabId = $this.data("tab");

    // タブの見た目を更新
    $(".nav-tab").removeClass("nav-tab-active");
    $this.addClass("nav-tab-active");

    // キャッシュがあればそれを使用
    if (cache[tabId]) {
      $(".aiap-panel").hide();
      $("#panel-" + tabId)
        .html(cache[tabId])
        .show();
      return;
    }

    // なければAjaxで取得
    $(".aiap-panel").hide();
    $("#panel-" + tabId)
      .html(
        '<div class="spinner is-active" style="float:none;width:100%;height:100px;padding:20px 0;text-align:center;background:rgba(255,255,255,0.8);"></div>'
      )
      .show();

    $.post(
      ajaxurl,
      {
        action: "aiap_load_tab",
        tab: tabId,
        _ajax_nonce: AIAP.nonce,
      },
      function (response) {
        if (response.success) {
          cache[tabId] = response.data;
          $("#panel-" + tabId).html(response.data);
        }
      }
    );
  });

  // テスト実行
  $(document).on("click", "#aiap-test-run", function (e) {
    e.preventDefault();

    // 実行中なら何もしない
    if (isRunning) return;

    const $button = $(this);
    const $progress = $("#aiap-progress");

    // 実行中状態に
    isRunning = true;
    $button.prop("disabled", true);
    $progress
      .html(
        '<div class="spinner is-active" style="float:left;margin:0 8px 0 0;"></div><span>題材生成中...</span>'
      )
      .show();

    // 非同期実行開始
    $.post(
      ajaxurl,
      {
        action: "aiap_run_start",
        _ajax_nonce: AIAP.nonce,
      },
      function (response) {
        if (!response.success) {
          isRunning = false;
          $button.prop("disabled", false);
          $progress.html(
            '<div class="notice notice-error"><p>エラーが発生しました</p></div>'
          );
          return;
        }

        // 進捗監視
        const jobId = response.data.job_id;
        const timer = setInterval(function () {
          $.post(
            ajaxurl,
            {
              action: "aiap_check_progress",
              job_id: jobId,
              _ajax_nonce: AIAP.nonce,
            },
            function (status) {
              if (!status.success) {
                clearInterval(timer);
                isRunning = false;
                $button.prop("disabled", false);
                $progress.html(
                  '<div class="notice notice-error"><p>エラーが発生しました</p></div>'
                );
                return;
              }

              const data = status.data;
              $progress.find("span").text(data.message);

              if (data.done) {
                clearInterval(timer);
                isRunning = false;
                $button.prop("disabled", false);

                let message =
                  '<div class="notice notice-success"><p>' + data.message;
                if (data.edit_url) {
                  message +=
                    ' <a href="' +
                    data.edit_url +
                    '" target="_blank">編集画面を開く</a>';
                }
                message += "</p></div>";
                $progress.html(message);
              }
            }
          );
        }, 1000);
      }
    );
  });

  // 初期表示
  $(".nav-tab-active").trigger("click");
});
