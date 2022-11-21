import {
    Card,
    Page,
    Layout,
    Form,
    FormLayout,
    TextField,
    TextContainer,
    Heading,
  } from "@shopify/polaris";
  import { TitleBar } from "@shopify/app-bridge-react";
  import { useState, useCallback } from "react";
  import { Toast } from "@shopify/app-bridge-react";
  import { useAppQuery, useAuthenticatedFetch } from "../hooks";

  export default function PageName() {
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const emailChange = useCallback((newEmail) => setEmail(newEmail), []);
    const pwChange = useCallback((newPassword) => setPassword(newPassword), []);
    const handleEmailClearButtonClick = useCallback(() => setEmail(""), []);
    const handlePasswordClearButtonClick = useCallback(() => setPassword(""), []);
    const fetch = useAuthenticatedFetch();

    const [errorActive, setErrorActive] = useState(false);
    const [successActive, setSuccessActive] = useState(false);

    const toggleSuccessActive = useCallback(() => setSuccessActive((successActive) => !successActive), []);
    const toggleErrorActive = useCallback(() => setErrorActive((errorActive) => !errorActive), []);

    var api_plugin_type = "";
    var customer_id = "";
    var user_email = "";
    var site_url = "";
    var token = "";

    // const toastMarkup = toastProps.content && !isRefetchingCount && (
    //     <Toast {...toastProps} onDismiss={() => setToastProps(emptyToastProps)} />
    //   );
    const toastMarkupError = errorActive ? (
        <Toast content="Invalid Inputs!" error onDismiss={toggleErrorActive} />
      ) : null;

      const toastMarkupSuccess = successActive ? (
        <Toast content="Connected successfully!" onDismiss={toggleSuccessActive} />
      ) : null;

    const handleSubmit = (event) => {
      console.log("abcd");
    };

    const titleClick = () => {
      var jsonData = {
        Email: email,
        Password: password,
      };
      console.log(JSON.stringify(jsonData));
      var responseClone;

      fetch(
        //"https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi",
        "/api/configuration/post",
        {
          method: "POST",
          body: JSON.stringify(jsonData),
          headers: {
            "Content-type": "application/json; charset=UTF-8",
          },
        }
      )
        .then(function (response) {
            responseClone = response.clone(); // 2
            return response.json();
        })
        .then((data) => {
            if(data.message=='input_error'){
                toggleErrorActive()
            }
            else {
                toggleSuccessActive()
            }
        })
        .catch((err) => {
          console.log(err.message);
        });
    };
    return (
      <Page narrowWidth>
        <TitleBar
          title="Connection to iCarry"
          primaryAction={{
            content: "Connect",
            onAction: titleClick,
          }}
        />
        <Layout>
          <Layout.Section>
            {toastMarkupError}
            {toastMarkupSuccess}
            <Card
              sectioned
              title="Connect to iCarry"
              // primaryFooterAction={{ content: "Connect to iCarry" }}
              // onClick={handleSubmit}
            >
              <Form onSubmit={handleSubmit}>
                <FormLayout>
                  <TextField
                    label="Email"
                    type="email"
                    value={email}
                    onChange={emailChange}
                    autoComplete="email"
                    clearButton
                    onClearButtonClick={handleEmailClearButtonClick}
                    placeholder="Example: example123@gmail.com"
                  />
                </FormLayout>
                <FormLayout>
                  <TextField
                    label="Password"
                    type="password"
                    value={password}
                    onChange={pwChange}
                    autoComplete="password"
                    clearButton
                    onClearButtonClick={handlePasswordClearButtonClick}
                  />
                </FormLayout>
              </Form>
            </Card>
          </Layout.Section>
        </Layout>
      </Page>
    );
  }
