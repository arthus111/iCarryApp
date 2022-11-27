import {
    Card,
    Page,
    Layout,
    Form,
    FormLayout,
    TextField,
    Button
  } from "@shopify/polaris";
  import { TitleBar } from "@shopify/app-bridge-react";
  import { useState, useCallback } from "react";
  import { Toast } from "@shopify/app-bridge-react";
  import { useAppQuery, useAuthenticatedFetch } from "../hooks";

  export default function AppSetting() {
    const emptyToastProps = { content: null };
    const [toastProps, setToastProps] = useState(emptyToastProps);
    const fetch = useAuthenticatedFetch();
    const [isLoading, setIsLoading] = useState(true);
    const [isFirstLoading, setIsFirstLoading] = useState(true);

    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const emailChange = useCallback((newEmail) => setEmail(newEmail), []);
    const pwChange = useCallback((newPassword) => setPassword(newPassword), []);
    const handleEmailClearButtonClick = useCallback(() => setEmail(""), []);
    const handlePasswordClearButtonClick = useCallback(() => setPassword(""), []);

    const toastMarkup = toastProps.content && (
        <Toast {...toastProps} duration={1500} onDismiss={() => setToastProps(emptyToastProps)} />
    );
    const {
    data
    } = useAppQuery({
    url: "/api/configuration/get",
    reactQueryOptions: {
        onSuccess: (res) => {
        setIsLoading(false);
        setIsFirstLoading(false);
        setEmail(res.data.email);
        setPassword(res.data.password);
        },
    },
    });

    const saveClick = () => {

        setIsLoading(true);
        var jsonData = {
            Email: email,
            Password: password,
        };
        var responseClone;
        fetch(
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
            setIsLoading(false);
            responseClone = response.clone(); // 2
            return response.json();
        })
        .then((data) => {

            if(data.message=='created'){
                setToastProps({
                    content: "Successfully saved!",
                    error: false
                });
            }
            else if(data.message=='updated'){
                setToastProps({
                    content: "Successfully updated!",
                    error: false
                });
            }
            else if(data.message=='error'){
                setToastProps({
                    content: "There is connection error!",
                    error: true
                });
            }
        })
        .catch((err) => {
          console.log(err.message);
        });
    };

    const connectCheckClick = () => {

        setIsLoading(true);
        var jsonData = {
            Email: email,
            Password: password,
        };
        var responseClone;
        fetch(
            "/api/configuration/check",
            {
            method: "POST",
            body: JSON.stringify(jsonData),
            headers: {
                "Content-type": "application/json; charset=UTF-8",
            },
            }
        )
        .then(function (response) {
            setIsLoading(false);
            responseClone = response.clone(); // 2
            return response.json();
        })
        .then((data) => {
            if(data.message=='site_url_error'){
                setToastProps({
                    content: "This app is not registered for this site!",
                    error: true
                });
            }
            else if(data.message=='plugin_error'){
                setToastProps({
                    content: "This app is not registered for Shopify!",
                    error: true
                });
            }
            else if(data.message=='input_error'){
                setToastProps({
                    content: "Email or password is not correct!",
                    error: true
                });
            }
            else if(data.message=='connected'){
                setToastProps({
                    content: "You are successfully connected!",
                    error: false
                });
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
            content: "Save",
            onAction: saveClick,
            loading: isLoading
          }}
        />
        <Layout>
          <Layout.Section>
          {toastMarkup}
            <Card
              sectioned
              title="Connect to iCarry"
              // primaryFooterAction={{ content: "Connect to iCarry" }}
              // onClick={handleSubmit}
            >
                <p>
                    Get iCarry Courier rates for each order based on your shipping and customer pin code. Using this app you can display iCarry courier enabling this iCarry  will update your Products and Checkout Page.
                </p>
              <Form style={{marginTop : 300}}>
                <FormLayout>
                  <TextField
                    label="Email"
                    type="email"
                    value={ isFirstLoading ? "" : email}
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
                <div style={{marginTop: 10}}>
                    <Button
                        primary
                        loading={isLoading}
                        onClick={connectCheckClick}
                    >Check Connectivity</Button>
                </div>
              </Form>
            </Card>
          </Layout.Section>
        </Layout>
      </Page>
    );
  }
